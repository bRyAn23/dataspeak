<?php

namespace Tests\Unit;

use App\Services\Ai\Clients\AnthropicClient;
use App\Services\Ai\LlmUnavailableException;
use App\Services\Ai\Schemas\ClaimOutputSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiScenario;
use Tests\TestCase;

class AnthropicClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic' => ['api_key' => 'sk-test', 'model' => 'claude-sonnet-5-5', 'base_url' => 'https://anthropic.test', 'version' => '2023-06-01', 'timeout' => 30, 'temperature' => 0.1, 'max_tokens' => 4096, 'context_tokens' => 150000]]);
    }

    private function messages(): array
    {
        return [['role' => 'system', 'content' => 'rules'], ['role' => 'user', 'content' => 'data'], ['role' => 'assistant', 'content' => 'draft'], ['role' => 'user', 'content' => 'fix it']];
    }

    public function test_schema_is_enforced_through_a_forced_tool_call_and_the_input_is_the_result(): void
    {
        $output = AiScenario::modelOutput();
        Http::fake(['anthropic.test/v1/messages' => Http::response([
            'model' => 'claude-sonnet-5-5', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'text', 'text' => 'Here you go'], ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'emit', 'input' => $output]],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 250],
        ])]);

        $result = (new AnthropicClient)->chat($this->messages(), ClaimOutputSchema::schema(), ['temperature' => 0.0, 'num_predict' => 800]);

        $this->assertTrue($result->isJson());
        $this->assertCount(5, $result->json['claims']);
        $this->assertSame([1200, 250, 'claude-sonnet-5-5'], [$result->inputTokens, $result->outputTokens, $result->model]);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->hasHeader('x-api-key', 'sk-test') && $request->hasHeader('anthropic-version', '2023-06-01')
                && $body['system'] === 'rules' && count($body['messages']) === 3 && $body['messages'][1]['role'] === 'assistant'
                && $body['tool_choice'] === ['type' => 'tool', 'name' => 'emit'] && $body['tools'][0]['input_schema'] === ClaimOutputSchema::schema()
                && $body['max_tokens'] === 800 && $body['temperature'] === 0.0 && ! isset($body['stream']);
        });
    }

    public function test_text_only_reply_is_reported_as_a_parse_problem_not_an_exception(): void
    {
        Http::fake(['anthropic.test/v1/messages' => Http::response(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'I cannot']], 'usage' => []])]);
        $result = (new AnthropicClient)->chat($this->messages(), ClaimOutputSchema::schema());
        $this->assertFalse($result->isJson());
        $this->assertStringContainsString('structured tool call', (string) $result->parseError);
    }

    public function test_failures_map_to_the_same_codes_as_ollama(): void
    {
        config(['services.anthropic.api_key' => '']);
        try {
            (new AnthropicClient)->chat($this->messages(), ClaimOutputSchema::schema());
            $this->fail('expected unreachable');
        } catch (LlmUnavailableException $e) {
            $this->assertSame('AI_UNAVAILABLE', $e->code_);
        }
        config(['services.anthropic.api_key' => 'sk-test']);

        Http::fake(['anthropic.test/v1/messages' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);
        try {
            (new AnthropicClient)->chat($this->messages(), ClaimOutputSchema::schema());
            $this->fail('expected http error');
        } catch (LlmUnavailableException $e) {
            $this->assertSame('AI_HTTP_ERROR', $e->code_);
        }

        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        try {
            (new AnthropicClient)->chat($this->messages(), ClaimOutputSchema::schema());
            $this->fail('expected timeout');
        } catch (LlmUnavailableException $e) {
            $this->assertSame('AI_TIMEOUT', $e->code_);
        }
    }
}
