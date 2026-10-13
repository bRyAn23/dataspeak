<?php

namespace App\Services\Ai;

use App\Enums\ClaimStatus;
use App\Models\Analysis;
use App\Models\Claim;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Schemas\ClaimOutputSchema;
use App\Support\Locale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * FEAT-I18N-003 Translates the verified narrative into another language without re-interpreting anything: the
 * model only rewords each statement template, every placeholder must survive untouched (same ids, same fields,
 * same count), no number may appear, and the text must be in the target script. Anything else is dropped and the
 * original statement keeps being shown for that claim.
 */
class ClaimTranslator
{
    public const PROMPT_VERSION = 'claim-translate-v1';

    public function __construct(private readonly LlmClient $llm) {}

    /** @return array{ai_status: string, model: ?string, translated: int, pending: int, total: int} */
    public function translate(Analysis $analysis, string $locale, int $attempt = 1): array
    {
        $locale = Locale::normalize($locale);
        $source = Locale::normalize($analysis->report?->language ?? 'en');
        $claims = $analysis->claims()->where('status', ClaimStatus::SUPPORTED)->where('in_report', true)->orderBy('position')->get();
        $pending = $locale === $source ? collect() : $claims->filter(fn (Claim $c) => ! isset(($c->statement_translations ?? [])[$locale]));
        $result = ['ai_status' => 'not_needed', 'model' => null, 'translated' => 0, 'pending' => $pending->count(), 'total' => $claims->count()];
        if ($pending->isEmpty()) {
            return $result;
        }
        if (! config('dataspeak.ai.enabled', true)) {
            return ['ai_status' => 'disabled'] + $result;
        }

        // Small batches sent concurrently (API drivers) or in sequence (a local model). Each accepted sentence is
        // stored as soon as its batch returns, so a failure or a retry only redoes what is still pending. Sentences
        // that come back missing or unacceptable get a second, one-at-a-time pass with the exact placeholders spelled
        // out: models tend to merge near-identical sentences in a batch, which left a few statements untranslated.
        $status = 'ready';
        $model = null;
        $translated = 0;
        $batches = $pending->chunk(self::batchSize($source, $locale, $attempt))->values();
        [$status, $model, $translated] = $this->runPass($batches, $source, $locale, self::options(self::batchSize($source, $locale, $attempt), $attempt), $status, $model, $translated, strict: false);
        $left = $pending->filter(fn (Claim $c) => ! isset(($c->statement_translations ?? [])[$locale]))->values();
        if ($status === 'ready' && $left->isNotEmpty()) {
            [$status, $model, $translated] = $this->runPass($left->map(fn (Claim $c) => collect([$c])), $source, $locale, self::options(1, 2), $status, $model, $translated, strict: true);
        }
        Log::info('claims.translated', ['analysis_id' => $analysis->public_id, 'locale' => $locale, 'translated' => $translated, 'pending' => $pending->count() - $translated, 'ai_status' => $status]);

        return ['ai_status' => $status, 'model' => $model, 'translated' => $translated, 'pending' => $pending->count() - $translated, 'total' => $claims->count()];
    }

    /**
     * Deterministic on the first attempt. A retry runs slightly warmer so it does not reproduce the same failure,
     * and the output is capped so a model stuck repeating itself (seen with Myanmar script) returns quickly as
     * invalid output instead of running into the timeout.
     *
     * @return array<string, int|float>
     */
    public static function options(int $sentences, int $attempt = 1): array
    {
        return ['temperature' => $attempt > 1 ? 0.3 : 0.0, 'num_ctx' => 8192, 'num_predict' => 400 * max(1, $sentences), 'timeout' => 180];
    }

    /**
     * One round of model calls over the given batches; returns the updated status, model tag and count.
     *
     * @param  Collection<int, Collection<int, Claim>>  $batches
     * @return array{0: string, 1: ?string, 2: int}
     */
    private function runPass($batches, string $source, string $locale, array $options, string $status, ?string $model, int $translated, bool $strict): array
    {
        if ($batches->isEmpty()) {
            return [$status, $model, $translated];
        }
        $requests = $batches->map(fn ($batch) => ['messages' => $this->messages($batch, $source, $locale, $strict), 'schema' => $this->schema(), 'options' => $options])->values()->all();
        try {
            $results = $this->llm->chatMany($requests);
        } catch (LlmUnavailableException $e) {
            return ['unavailable', $model, $translated];
        }
        $list = $batches->values();
        foreach ($results as $i => $out) {
            $model = $out->model ?: $model;
            if (! $out->isJson()) {
                $status = 'invalid_output';

                continue;
            }
            $translated += $this->store($list[$i], (array) ($out->json['claims'] ?? []), $locale);
        }

        return [$status, $model, $translated];
    }

    /** Sentences per model call; a retry works in pairs so one sentence the model loops on cannot block the rest. */
    public static function batchSize(string $source, string $locale, int $attempt = 1): int
    {
        if ($attempt > 1) {
            return 2;
        }

        return in_array('my', [$source, $locale], true) ? 4 : 8;
    }

    /** Saves the acceptable rows of one batch and returns how many were stored. */
    private function store($batch, array $rows, string $locale): int
    {
        $byId = $batch->keyBy('public_id');
        $stored = 0;
        $rejected = ['unknown_id' => 0, 'unacceptable' => 0];
        foreach ($rows as $row) {
            $claim = $byId->get((string) ($row['id'] ?? ''));
            $text = self::normalizeText((string) ($row['text'] ?? ''), $locale);
            if ($claim === null || ! $this->acceptable($claim->statement, $text, $locale)) {
                $rejected[$claim === null ? 'unknown_id' : 'unacceptable']++;

                continue;
            }
            $translations = (array) ($claim->statement_translations ?? []);
            $translations[$locale] = $text;
            $claim->forceFill(['statement_translations' => $translations])->save();
            $stored++;
        }
        $missing = $batch->count() - count($rows);
        if ($rejected['unknown_id'] + $rejected['unacceptable'] + max(0, $missing) > 0) {
            Log::info('claims.translation_rejected', ['locale' => $locale, 'asked' => $batch->count(), 'returned' => count($rows), 'unknown_id' => $rejected['unknown_id'], 'unacceptable' => $rejected['unacceptable']]);
        }

        return $stored;
    }

    private const FULL_WIDTH = ['。' => '.', '，' => ',', '（' => ' (', '）' => ') ', '：' => ':', '；' => ';', '！' => '!', '？' => '?', '、' => ', ', "\u{3000}" => ' '];

    /**
     * Collapses whitespace and, outside Chinese, swaps the full-width punctuation the model carries over from a
     * Chinese source (a Thai sentence ending in 。 is a correct translation with the wrong full stop).
     */
    public static function normalizeText(string $text, string $locale): string
    {
        if (Locale::normalize($locale) !== 'zh') {
            $text = strtr($text, self::FULL_WIDTH);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Same placeholders (as a multiset), no digits outside them, written in the target language. */
    public function acceptable(string $original, string $translation, string $locale): bool
    {
        if ($translation === '' || mb_strlen($translation) > 600) {
            return false;
        }
        preg_match_all(ClaimOutputSchema::PLACEHOLDER_REGEX, $original, $a);
        preg_match_all(ClaimOutputSchema::PLACEHOLDER_REGEX, $translation, $b);
        $wanted = $a[0];
        $got = $b[0];
        sort($wanted);
        sort($got);
        if ($wanted !== $got) {
            return false;
        }
        $stripped = preg_replace(ClaimOutputSchema::PLACEHOLDER_REGEX, '', $translation) ?? $translation;
        if (preg_match('/\p{N}/u', $stripped)) {
            return false;
        }

        return Locale::inLanguage($stripped, $locale);
    }

    private function messages($claims, string $source, string $locale, bool $strict = false): array
    {
        $from = Locale::name($source);
        $to = Locale::name($locale);
        $system = implode("\n", [
            "You translate sentences of a verified business report from {$from} into {$to}.",
            'Rules:',
            '- Translate the meaning faithfully; do not add, drop or soften anything.',
            '- Every placeholder written like {ev_xxxxxxxxxxxx.field} is a value filled in later: copy each one exactly as it is, the same number of times, in the place where the value belongs in the target language.',
            '- Never write any digit; all numbers come from placeholders.',
            '- Keep codes, units and currency codes unchanged.',
            '- Plain, natural business language for executives.',
            '- Return one entry per sentence with the same id, even when sentences look alike: each one must be translated separately.',
            'Answer only with JSON that matches the schema.',
        ]);
        if ($strict) {
            preg_match_all(ClaimOutputSchema::PLACEHOLDER_REGEX, implode(' ', $claims->map(fn (Claim $c) => $c->statement)->all()), $m);
            $system .= "\nThis sentence was returned incorrectly before. Translate it alone. It must contain exactly these placeholders, each once, unchanged: ".implode(' ', array_unique($m[0])).'. Write everything else in '.Locale::name($locale).' script only, with no digits.';
        }
        $payload = json_encode(['sentences' => $claims->map(fn (Claim $c) => ['id' => $c->public_id, 'text' => $c->statement])->values()->all()], JSON_UNESCAPED_UNICODE);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Translate these sentences.\n<sentences_json>\n{$payload}\n</sentences_json>"],
        ];
    }

    private function schema(): array
    {
        return ['type' => 'object', 'properties' => ['claims' => ['type' => 'array', 'items' => ['type' => 'object',
            'properties' => ['id' => ['type' => 'string'], 'text' => ['type' => 'string']], 'required' => ['id', 'text']]]], 'required' => ['claims']];
    }
}
