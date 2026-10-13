<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\LlmResult;
use App\Services\Ai\LlmUnavailableException;

/**
 * Minimal chat interface so the Ollama client can be swapped for a vLLM (OpenAI-compatible) client
 * without touching orchestration, validation or rendering.
 */
interface LlmClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema  JSON schema the output must satisfy
     * @param  array<string, mixed>  $options  temperature, seed, num_ctx, think
     *
     * @throws LlmUnavailableException when the model cannot be reached or errors
     */
    public function chat(array $messages, array $schema, array $options = []): LlmResult;

    /**
     * Several independent calls at once. Clients that can run them concurrently (HTTP APIs) do; a local model
     * server answers them one after another. Results keep the order of the requests.
     *
     * @param  list<array{messages: array, schema: array, options?: array}>  $requests
     * @return list<LlmResult>
     *
     * @throws LlmUnavailableException when none of the calls could be made
     */
    public function chatMany(array $requests): array;

    public function modelTag(): string;
}
