<?php

namespace App\Services\Ai\Clients;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\LlmResult;
use App\Services\Ai\LlmUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Claude Messages API (POST /v1/messages). The output schema every caller passes is enforced by exposing it as
 * the input schema of a single forced tool call, so the reply is a JSON object that matches the schema and
 * needs no text parsing. Same contract as OllamaClient: only evidence, placeholders and labels are sent, never
 * dataset rows; request bodies are never logged. Selected with AI_LLM_DRIVER=anthropic. chatMany() runs its
 * calls concurrently, which is what makes batched column suggestions fast.
 */
class AnthropicClient implements LlmClient
{
    private const TOOL = 'emit';

    public function modelTag(): string
    {
        return (string) config('services.anthropic.model');
    }

    public function chat(array $messages, array $schema, array $options = []): LlmResult
    {
        return $this->chatMany([['messages' => $messages, 'schema' => $schema, 'options' => $options]])[0];
    }

    public function chatMany(array $requests): array
    {
        $cfg = (array) config('services.anthropic');
        if (trim((string) ($cfg['api_key'] ?? '')) === '') {
            Log::warning('ai.unreachable', ['method' => 'anthropic.messages', 'code' => 'AI_UNAVAILABLE', 'reason' => 'no_api_key']);
            throw LlmUnavailableException::unreachable('ANTHROPIC_API_KEY is not set');
        }
        $requests = array_values($requests);
        if ($requests === []) {
            return [];
        }

        $started = microtime(true);
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (array $r, int $i) => $this->prepare($pool->as((string) $i), $cfg, $r['options'] ?? [])->post('/v1/messages', $this->body($cfg, $r['messages'], $r['schema'], $r['options'] ?? [])),
            $requests,
            array_keys($requests),
        ));
        $durationMs = (int) ((microtime(true) - $started) * 1000);

        $results = [];
        foreach ($requests as $i => $request) {
            $response = $responses[(string) $i] ?? null;
            if (! $response instanceof Response) {
                $message = $response instanceof \Throwable ? strtolower($response->getMessage()) : '';
                $timedOut = str_contains($message, 'timed out') || str_contains($message, 'timeout');
                Log::warning('ai.unreachable', ['method' => 'anthropic.messages', 'code' => $timedOut ? 'AI_TIMEOUT' : 'AI_UNAVAILABLE']);
                throw $timedOut ? LlmUnavailableException::timeout() : LlmUnavailableException::unreachable();
            }
            if (! $response->successful()) {
                Log::warning('ai.http_error', ['method' => 'anthropic.messages', 'status_code' => $response->status(), 'error_type' => $response->json('error.type')]);
                throw LlmUnavailableException::httpError($response->status());
            }
            $results[] = $this->parse($response, $durationMs);
        }

        return $results;
    }

    private function prepare(PendingRequest $request, array $cfg, array $options): PendingRequest
    {
        return $request
            ->baseUrl(rtrim((string) ($cfg['base_url'] ?? 'https://api.anthropic.com'), '/'))
            ->withHeaders(['x-api-key' => (string) $cfg['api_key'], 'anthropic-version' => (string) ($cfg['version'] ?? '2023-06-01')])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) ($options['timeout'] ?? $cfg['timeout'] ?? 120))
            ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException || (method_exists($e, 'response') && in_array(optional($e->response)->status(), [429, 529], true)), throw: false);
    }

    private function body(array $cfg, array $messages, array $schema, array $options): array
    {
        // The Messages API takes the system prompt separately and alternating user/assistant turns.
        $system = implode("\n\n", array_map(fn ($m) => (string) ($m['content'] ?? ''), array_filter($messages, fn ($m) => ($m['role'] ?? '') === 'system')));
        $turns = array_values(array_map(
            fn ($m) => ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) ($m['content'] ?? '')],
            array_filter($messages, fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant'], true)),
        ));
        if ($turns === []) {
            $turns = [['role' => 'user', 'content' => 'Answer with the tool.']];
        }
        $body = [
            'model' => $this->modelTag(),
            'max_tokens' => (int) ($options['num_predict'] ?? $cfg['max_tokens'] ?? 4096),
            'temperature' => max(0.0, min(1.0, (float) ($options['temperature'] ?? $cfg['temperature'] ?? 0.1))),
            'messages' => $turns,
            'tools' => [[
                'name' => self::TOOL,
                'description' => 'Return the answer as a JSON object that matches the schema exactly.',
                'input_schema' => $schema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => self::TOOL],
        ];
        if ($system !== '') {
            $body['system'] = $system;
        }

        return $body;
    }

    private function parse(Response $response, int $durationMs): LlmResult
    {
        $json = null;
        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === self::TOOL && is_array($block['input'] ?? null)) {
                $json = $block['input'];
                break;
            }
        }
        $stop = (string) $response->json('stop_reason');
        $raw = $json !== null ? (string) json_encode($json, JSON_UNESCAPED_UNICODE) : (string) collect((array) $response->json('content', []))->pluck('text')->filter()->implode("\n");
        $parseError = $json === null ? 'model did not return the structured tool call (stop_reason '.$stop.')' : null;
        if ($json !== null && $stop === 'max_tokens') {
            Log::warning('ai.output_truncated', ['method' => 'anthropic.messages', 'output_tokens' => $response->json('usage.output_tokens')]);
        }

        return new LlmResult(
            json: $json,
            rawContent: $raw,
            model: (string) ($response->json('model') ?: $this->modelTag()),
            inputTokens: is_numeric($response->json('usage.input_tokens')) ? (int) $response->json('usage.input_tokens') : null,
            outputTokens: is_numeric($response->json('usage.output_tokens')) ? (int) $response->json('usage.output_tokens') : null,
            durationMs: $durationMs,
            parseError: $parseError,
        );
    }
}
