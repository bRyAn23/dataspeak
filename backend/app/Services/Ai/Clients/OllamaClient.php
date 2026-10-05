<?php

namespace App\Services\Ai\Clients;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\LlmResult;
use App\Services\Ai\LlmUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ollama native chat API (POST /api/chat, stream:false, schema-constrained via `format`).
 * Reasoning output is disabled with `think:false` and any <think> block is stripped before parsing.
 * Request bodies are never logged.
 */
class OllamaClient implements LlmClient
{
    public function modelTag(): string
    {
        return (string) config('services.ollama.model');
    }

    public function chat(array $messages, array $schema, array $options = []): LlmResult
    {
        $cfg = (array) config('services.ollama');
        $body = [
            'model' => $this->modelTag(),
            'messages' => $messages,
            'stream' => false,
            'format' => $schema,
            'think' => (bool) ($options['think'] ?? $cfg['think'] ?? false),
            'options' => [
                'temperature' => (float) ($options['temperature'] ?? $cfg['temperature']),
                'seed' => (int) ($options['seed'] ?? $cfg['seed']),
                'num_ctx' => (int) ($options['num_ctx'] ?? $cfg['num_ctx']),
            ],
        ];
        if (isset($options['num_predict'])) {
            $body['options']['num_predict'] = (int) $options['num_predict']; // output cap; callers set it for translations
        }

        $started = microtime(true);
        try {
            $response = Http::baseUrl(rtrim((string) $cfg['base_url'], '/'))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout((int) ($options['timeout'] ?? $cfg['timeout']))
                ->post('/api/chat', $body);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout');
            Log::warning('ai.unreachable', ['method' => 'ollama.chat', 'code' => $timedOut ? 'AI_TIMEOUT' : 'AI_UNAVAILABLE']);
            throw $timedOut ? LlmUnavailableException::timeout() : LlmUnavailableException::unreachable();
        }
        $durationMs = (int) ((microtime(true) - $started) * 1000);

        if (! $response->successful()) {
            Log::warning('ai.http_error', ['method' => 'ollama.chat', 'status_code' => $response->status()]);
            throw LlmUnavailableException::httpError($response->status());
        }

        $content = (string) $response->json('message.content', '');
        $content = trim((string) preg_replace('/<think>.*?<\/think>/su', '', $content));
        $decoded = json_decode($content, true);
        $parseError = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? null : 'model output is not a JSON object: '.json_last_error_msg();

        $totalNs = $response->json('total_duration');

        return new LlmResult(
            json: $parseError === null ? $decoded : null,
            rawContent: $content,
            model: (string) ($response->json('model') ?: $this->modelTag()),
            inputTokens: is_numeric($response->json('prompt_eval_count')) ? (int) $response->json('prompt_eval_count') : null,
            outputTokens: is_numeric($response->json('eval_count')) ? (int) $response->json('eval_count') : null,
            durationMs: is_numeric($totalNs) ? (int) ($totalNs / 1_000_000) : $durationMs,
            parseError: $parseError,
        );
    }
}
