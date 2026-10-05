<?php

namespace App\Services\Ai;

use App\Enums\ClaimStatus;
use App\Models\Analysis;
use App\Models\Claim;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Schemas\ClaimOutputSchema;
use App\Support\Locale;
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

        // Small batches: Burmese and Thai are token-heavy for the model, and a whole report in one call overran the
        // timeout and saved nothing. Each accepted sentence is stored as soon as its batch returns, so a failure
        // or a retry only redoes what is still pending.
        $status = 'ready';
        $model = null;
        $translated = 0;
        foreach ($pending->chunk(self::batchSize($source, $locale, $attempt)) as $batch) {
            try {
                $out = $this->llm->chat($this->messages($batch, $source, $locale), $this->schema(), self::options($batch->count(), $attempt));
            } catch (LlmUnavailableException $e) {
                $status = 'unavailable';
                break;
            }
            $model = $out->model;
            if (! $out->isJson()) {
                $status = 'invalid_output';

                continue;
            }
            $translated += $this->store($batch, (array) ($out->json['claims'] ?? []), $locale);
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
        foreach ($rows as $row) {
            $claim = $byId->get((string) ($row['id'] ?? ''));
            $text = self::normalizeText((string) ($row['text'] ?? ''), $locale);
            if ($claim === null || ! $this->acceptable($claim->statement, $text, $locale)) {
                continue;
            }
            $translations = (array) ($claim->statement_translations ?? []);
            $translations[$locale] = $text;
            $claim->forceFill(['statement_translations' => $translations])->save();
            $stored++;
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

    private function messages($claims, string $source, string $locale): array
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
            '- Return one entry per sentence with the same id.',
            'Answer only with JSON that matches the schema.',
        ]);
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
