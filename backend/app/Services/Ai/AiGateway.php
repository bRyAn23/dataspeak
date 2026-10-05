<?php

namespace App\Services\Ai;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Models\AiGeneration;
use App\Models\Analysis;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Prompts\ExecutiveInterpretationPrompt;
use App\Services\Ai\Schemas\ClaimOutputSchema;
use App\Support\Locale;
use Illuminate\Support\Facades\Log;

/**
 * MOD-09 orchestration: builds the evidence payload, calls the LLM with a schema-constrained request,
 * parses strictly, retries once on schema failure, and records everything on ai_generations.
 * Never throws for availability problems: the returned generation carries FAILED/SKIPPED + error_code
 * so the report job can degrade gracefully. Never logs the prompt payload.
 */
class AiGateway
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly EvidencePayloadBuilder $builder,
        private readonly ExecutiveInterpretationPrompt $prompt,
    ) {}

    public function interpret(Analysis $analysis, ?PlaceholderResolver $resolver = null, bool $persist = true, ?string $locale = null): AiGeneration
    {
        $locale = Locale::normalize($locale ?? $resolver?->locale() ?? app()->getLocale());
        $resolver ??= PlaceholderResolver::for($analysis, $locale);
        $cfg = (array) config('dataspeak.ai');
        $ollama = (array) config('services.ollama');
        $built = $this->builder->build($analysis, $resolver);
        $language = Locale::name($locale); // FEAT-I18N-003: the report language chosen by the user
        $fields = (array) $cfg['placeholder_fields'];
        $payloadJson = json_encode($built['payload'], JSON_UNESCAPED_UNICODE);

        $generation = new AiGeneration([
            'generation_type' => AiGenerationType::CLAIM_GENERATION,
            'status' => AiGenerationStatus::RUNNING,
            'attempt_no' => (int) $analysis->aiGenerations()->max('attempt_no') + 1,
            'model' => $this->client->modelTag(),
            'prompt_version' => $this->prompt->version(),
            'request_payload' => [
                'meta' => [
                    'temperature' => (float) $ollama['temperature'],
                    'seed' => (int) $ollama['seed'],
                    'num_ctx' => (int) $ollama['num_ctx'],
                    'language' => $language,
                    'locale' => $locale,
                    'payload_hash' => hash('sha256', (string) $payloadJson),
                    'estimated_tokens' => $built['estimated_tokens'],
                ],
                'evidence_keys' => $built['evidence_keys'],
                'insight_ranks' => $built['insight_ranks'],
                'omitted' => $built['omitted'],
            ],
        ]);
        $generation->analysis()->associate($analysis);
        if ($persist) {
            $generation->save();
        }

        if (! ($cfg['enabled'] ?? true)) {
            return $this->finish($generation, AiGenerationStatus::SKIPPED, 'AI_DISABLED', 0, $persist);
        }
        if (empty($built['evidence_keys'])) {
            return $this->finish($generation, AiGenerationStatus::SKIPPED, 'AI_NO_EVIDENCE', 0, $persist);
        }

        $messages = $this->prompt->messages($built['payload'], $language, $fields);
        $schema = ClaimOutputSchema::schema();
        $attempts = 0;
        $maxAttempts = 1 + (int) ($cfg['max_schema_retries'] ?? 1);
        $totalMs = 0;
        $inputTokens = $outputTokens = 0;
        $lastProblems = [];

        while ($attempts < $maxAttempts) {
            $attempts++;
            try {
                $result = $this->client->chat($messages, $schema);
            } catch (LlmUnavailableException $e) {
                Log::warning('ai.generation_failed', ['analysis_id' => $analysis->public_id, 'code' => $e->code_, 'method' => 'chat']);

                return $this->finish($generation, AiGenerationStatus::FAILED, $e->code_, $totalMs, $persist, ['attempts' => $attempts]);
            }
            $totalMs += $result->durationMs;
            $inputTokens += (int) $result->inputTokens;
            $outputTokens += (int) $result->outputTokens;

            $problems = $result->isJson() ? ClaimOutputSchema::problems($result->json) : [(string) $result->parseError];
            if ($problems === []) {
                $generation->fill([
                    'response_payload' => $result->json,
                    'input_tokens' => $inputTokens ?: null,
                    'output_tokens' => $outputTokens ?: null,
                    'duration_ms' => $totalMs,
                    'status' => AiGenerationStatus::SUCCEEDED,
                    'model' => $result->model,
                ]);
                $generation->request_payload = array_merge($generation->request_payload, ['attempts' => $attempts]);
                if ($persist) {
                    $generation->save();
                }
                Log::info('ai.generation_succeeded', [
                    'analysis_id' => $analysis->public_id, 'method' => $generation->model, 'duration_ms' => $totalMs,
                    'row_count' => count($result->json['claims'] ?? []), 'code' => $generation->prompt_version,
                ]);

                return $generation;
            }

            $lastProblems = $problems;
            $messages[] = ['role' => 'assistant', 'content' => mb_substr($result->rawContent, 0, 4000)];
            $messages[] = ['role' => 'user', 'content' => $this->prompt->retryMessage($problems)];
        }

        Log::warning('ai.generation_failed', ['analysis_id' => $analysis->public_id, 'code' => 'AI_SCHEMA_INVALID', 'duration_ms' => $totalMs]);

        return $this->finish($generation, AiGenerationStatus::FAILED, 'AI_SCHEMA_INVALID', $totalMs, $persist, [
            'attempts' => $attempts, 'problems' => array_slice($lastProblems, 0, 20), 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens,
        ]);
    }

    /**
     * One bounded repair round: claims rejected only for literal numbers or bad placeholders are sent back
     * with the exact placeholders available for the evidence they cite. The model rewrites; software never
     * edits claim text. Returns the rewritten claims (same shape) or [] when the model fails again.
     *
     * @param  list<array<string, mixed>>  $claims
     * @return list<array<string, mixed>>
     */
    public function repair(AiGeneration $generation, array $claims, PlaceholderResolver $resolver): array
    {
        if ($claims === [] || ! (config('dataspeak.ai.enabled') ?? true)) {
            return [];
        }
        $menu = [];
        foreach ($claims as $claim) {
            foreach ((array) ($claim['evidence_ids'] ?? []) as $id) {
                if (! $resolver->has($id) || isset($menu[$id])) {
                    continue;
                }
                $e = $resolver->evidence($id);
                $menu[$id] = ['metric' => $e?->metric, 'family' => $e ? strtolower($e->analysis_family->value) : null, 'sentence' => $e ? EvidenceSentences::for($e, $resolver) : null]
                    + array_filter($resolver->viewFor($id), fn ($v) => $v !== null && $v !== '');
            }
        }
        $messages = [
            ['role' => 'system', 'content' => $this->prompt->system(Locale::name((string) ($generation->request_payload['meta']['locale'] ?? $resolver->locale())), (array) config('dataspeak.ai.placeholder_fields'))],
            ['role' => 'user', 'content' => $this->prompt->repairMessage($claims, $menu)],
        ];
        $started = microtime(true);
        try {
            $result = $this->client->chat($messages, ClaimOutputSchema::schema());
        } catch (LlmUnavailableException $e) {
            Log::warning('ai.repair_failed', ['analysis_id' => $generation->analysis?->public_id, 'code' => $e->code_]);

            return [];
        }
        $problems = $result->isJson() ? ClaimOutputSchema::problems($result->json) : [(string) $result->parseError];
        $meta = $generation->request_payload;
        $meta['repair'] = ['attempted' => count($claims), 'duration_ms' => $result->durationMs ?: (int) ((microtime(true) - $started) * 1000), 'schema_valid' => $problems === []];
        $generation->request_payload = $meta;
        $generation->input_tokens = (int) $generation->input_tokens + (int) $result->inputTokens;
        $generation->output_tokens = (int) $generation->output_tokens + (int) $result->outputTokens;
        $generation->duration_ms = (int) $generation->duration_ms + $result->durationMs;
        if ($generation->exists) {
            $generation->save();
        }
        if ($problems !== []) {
            return [];
        }

        return array_merge((array) ($result->json['claims'] ?? []), (array) ($result->json['limitations'] ?? []));
    }

    private function finish(AiGeneration $generation, AiGenerationStatus $status, string $code, int $ms, bool $persist, array $extra = []): AiGeneration
    {
        $generation->fill([
            'status' => $status,
            'error_code' => $code,
            'duration_ms' => $ms,
            'input_tokens' => $extra['input_tokens'] ?? null,
            'output_tokens' => $extra['output_tokens'] ?? null,
            'response_payload' => isset($extra['problems']) ? ['problems' => $extra['problems']] : null,
        ]);
        $generation->request_payload = array_merge($generation->request_payload, array_intersect_key($extra, ['attempts' => 1]));
        if ($persist) {
            $generation->save();
        }

        return $generation;
    }
}
