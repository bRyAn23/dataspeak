<?php

namespace App\Services\Ai\Clients;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\LlmResult;
use App\Services\Ai\ModelAccessCoordinator;

/**
 * DEF-09: wraps any LlmClient so that every single model request passes through the ModelAccessCoordinator.
 * When the coordinator is disabled (API providers), calls go straight to the inner client, including its
 * concurrent chatMany.
 */
class CoordinatedLlmClient implements LlmClient
{
    public function __construct(private readonly LlmClient $inner, private readonly ModelAccessCoordinator $coordinator) {}

    public function chat(array $messages, array $schema, array $options = []): LlmResult
    {
        return $this->coordinator->run(fn () => $this->inner->chat($messages, $schema, $options));
    }

    public function chatMany(array $requests): array
    {
        if (! $this->coordinator->enabled()) {
            return $this->inner->chatMany($requests);
        }

        // One lock per request, so an interactive request can slip in between two background batches.
        return array_map(fn (array $r) => $this->chat($r['messages'], $r['schema'], $r['options'] ?? []), array_values($requests));
    }

    public function modelTag(): string
    {
        return $this->inner->modelTag();
    }

    public function inner(): LlmClient
    {
        return $this->inner;
    }
}
