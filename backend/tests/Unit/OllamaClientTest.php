<?php

namespace Tests\Unit;

use App\Services\Ai\Clients\OllamaClient;
use App\Services\Ai\LlmUnavailableException;
use App\Services\Ai\Schemas\ClaimOutputSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiScenario;
use Tests\TestCase;

class OllamaClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ollama.base_url' => 'http://ollama.test', 'services.ollama.model' => 'qwen3:8b', 'services.ollama.seed' => 7, 'services.ollama.temperature' => 0.2]);
    }

    private function messages(): array
    {
        return [['role' => 'system', 'content' => 'rules'], ['role' => 'user', 'content' => 'data']];
    }

    public function test_success_sends_schema_constrained_request_and_parses_output(): void
    {
        $body = AiScenario::ollamaResponse(AiScenario::modelOutput());
        $body['message']['content'] = "<think>reasoning that must be stripped 123</think>\n".$body['message']['content'];
        Http::fake(['ollama.test/api/chat' => Http::response($body)]);

        $result = (new OllamaClient)->chat($this->messages(), ClaimOutputSchema::schema());

        $this->assertTrue($result->isJson());
        $this->assertCount(5, $result->json['claims']);
        $this->assertSame(1500, $result->inputTokens);
        $this->assertSame(300, $result->outputTokens);
        $this->assertSame(4200, $result->durationMs);
        $this->assertStringNotContainsString('<think>', $result->rawContent);
        Http::assertSent(function (Request $r) {
            $data = $r->data();

            return $r->url() === 'http://ollama.test/api/chat'
                && $data['stream'] === false && $data['think'] === false && $data['model'] === 'qwen3:8b'
                && $data['format']['required'] === ['claims', 'limitations']
                && $data['options']['seed'] === 7 && $data['options']['temperature'] === 0.2 && isset($data['options']['num_ctx']);
        });
    }

    public function test_malformed_json_is_reported_not_thrown(): void
    {
        $body = AiScenario::ollamaResponse([]);
        $body['message']['content'] = 'Here is my answer: {"claims": [';
        Http::fake(['ollama.test/api/chat' => Http::response($body)]);

        $result = (new OllamaClient)->chat($this->messages(), ClaimOutputSchema::schema());
        $this->assertFalse($result->isJson());
        $this->assertNotNull($result->parseError);
    }

    public function test_connection_refused_raises_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $this->expectExceptionObject(LlmUnavailableException::unreachable());
        (new OllamaClient)->chat($this->messages(), []);
    }

    public function test_timeout_raises_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('Operation timed out after 300000 milliseconds'));
        try {
            (new OllamaClient)->chat($this->messages(), []);
            $this->fail('expected exception');
        } catch (LlmUnavailableException $e) {
            $this->assertSame('AI_TIMEOUT', $e->code_);
        }
    }

    public function test_http_error_raises_http_error(): void
    {
        Http::fake(['ollama.test/api/chat' => Http::response('model not found', 404)]);
        try {
            (new OllamaClient)->chat($this->messages(), []);
            $this->fail('expected exception');
        } catch (LlmUnavailableException $e) {
            $this->assertSame('AI_HTTP_ERROR', $e->code_);
        }
    }
}
