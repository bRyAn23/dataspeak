<?php

namespace App\Services\Ai;

/** Parsed model output plus the metadata persisted on ai_generations. Never contains dataset rows. */
final class LlmResult
{
    /** @param  array<string, mixed>|null  $json  parsed content, null when the content was not valid JSON */
    public function __construct(
        public readonly ?array $json,
        public readonly string $rawContent,
        public readonly string $model,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly int $durationMs,
        public readonly ?string $parseError = null,
    ) {}

    public function isJson(): bool
    {
        return $this->json !== null;
    }
}
