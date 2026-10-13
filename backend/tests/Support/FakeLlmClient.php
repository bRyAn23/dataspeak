<?php

namespace Tests\Support;

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\LlmResult;
use App\Services\Ai\LlmUnavailableException;

/** In-process stand-in for Ollama: returns a fixed JSON document or throws "unreachable". */
class FakeLlmClient implements LlmClient
{
    public int $calls = 0;

    /** @var list<array{role: string, content: string}> */
    public array $lastMessages = [];

    /** @var list<array|null> responses served in order before falling back to */
    public array $queue = [];

    public function __construct(private readonly ?array $json, private readonly bool $down = false, array $queue = [])
    {
        $this->queue = $queue;
    }

    public function chat(array $messages, array $schema, array $options = []): LlmResult
    {
        $this->calls++;
        $this->lastMessages = $messages;
        if ($this->down) {
            throw LlmUnavailableException::unreachable();
        }

        $json = $this->queue !== [] ? array_shift($this->queue) : $this->json;

        return new LlmResult($json, (string) json_encode($json), 'qwen3:8b', 10, 10, 5);
    }

    public function chatMany(array $requests): array
    {
        return array_map(fn (array $r) => $this->chat($r['messages'], $r['schema'], $r['options'] ?? []), array_values($requests));
    }

    public function modelTag(): string
    {
        return 'qwen3:8b';
    }
}
