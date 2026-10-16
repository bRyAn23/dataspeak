<?php

namespace Tests\Unit;

use App\Enums\AiGenerationStatus;
use App\Jobs\GenerateReportJob;
use App\Services\Ai\Clients\CoordinatedLlmClient;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\LlmResult;
use App\Services\Ai\LlmUnavailableException;
use App\Services\Ai\ModelAccessCoordinator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * DEF-09 scheduler tests. Deterministic: the cache is the array store, the "model" is a fake client, and
 * waits are shortened through config. No model is contacted (the real-model contention scenario is the
 * scripted integration test in docs/testing/unit7/final-verification).
 */
class ModelAccessCoordinatorTest extends TestCase
{
    private ModelAccessCoordinator $c;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('dataspeak.ai.driver', 'ollama');
        config()->set('dataspeak.ai.coordinator', ['enabled' => 'auto', 'lock_ttl_seconds' => 30, 'interactive_wait_seconds' => 1, 'background_wait_seconds' => 1, 'background_yield_seconds' => 1, 'poll_ms' => 50]);
        Cache::flush();
        $this->c = new ModelAccessCoordinator;
    }

    private function fake(?callable $behaviour = null): LlmClient
    {
        return new class($behaviour) implements LlmClient
        {
            public int $calls = 0;

            public function __construct(private $behaviour) {}

            public function chat(array $messages, array $schema, array $options = []): LlmResult
            {
                $this->calls++;
                if ($this->behaviour) {
                    ($this->behaviour)();
                }

                return new LlmResult(json: ['ok' => true], rawContent: '{"ok":true}', model: 'fake', inputTokens: 1, outputTokens: 1, durationMs: 1, parseError: null);
            }

            public function chatMany(array $requests): array
            {
                return array_map(fn ($r) => $this->chat($r['messages'], $r['schema'], $r['options'] ?? []), $requests);
            }

            public function modelTag(): string
            {
                return 'fake';
            }
        };
    }

    /** DEF09-01: an interactive (narrative) request runs at once when the model is free, and leaves no lock behind. */
    public function test_def09_01_interactive_request_runs_when_model_is_free(): void
    {
        $client = new CoordinatedLlmClient($this->fake(), $this->c);
        $result = $client->chat([], []);
        $this->assertSame(['ok' => true], $result->json);
        $this->assertSame(0, $this->c->interactiveWaiting());
        $this->assertTrue(Cache::lock(ModelAccessCoordinator::lockKey(), 1)->get(), 'lock must be released after the request');
    }

    /** DEF09-02: background translation runs normally when nothing interactive is waiting. */
    public function test_def09_02_background_request_runs_when_nothing_is_waiting(): void
    {
        $client = new CoordinatedLlmClient($fake = $this->fake(), $this->c);
        $t0 = microtime(true);
        $this->c->asBackground(fn () => $client->chat([], []));
        $this->assertSame(1, $fake->calls);
        $this->assertLessThan(0.5, microtime(true) - $t0, 'no yielding when no interactive request is waiting');
    }

    /** DEF09-03: while an interactive request is waiting, new background work steps aside first. */
    public function test_def09_03_background_yields_while_interactive_is_waiting(): void
    {
        Cache::put(ModelAccessCoordinator::waitingKey(), 1, 60); // an interactive request is waiting somewhere
        Log::spy();
        $client = new CoordinatedLlmClient($fake = $this->fake(), $this->c);
        $t0 = microtime(true);
        $this->c->asBackground(fn () => $client->chat([], []));
        $elapsed = microtime(true) - $t0;
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'background waited the whole yield bound before proceeding');
        $this->assertSame(1, $fake->calls);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === 'llm.background.yielded' && ($ctx['proceeding_after_bound'] ?? false) === true)->once();
    }

    /** DEF09-04: a request already holding the model is never interrupted; the interactive wait is bounded and ends in AI_BUSY. */
    public function test_def09_04_interactive_wait_is_bounded_when_a_translation_holds_the_model(): void
    {
        $held = Cache::lock(ModelAccessCoordinator::lockKey(), 30);
        $this->assertTrue($held->get(), 'simulate an in-flight background request');
        $client = new CoordinatedLlmClient($fake = $this->fake(), $this->c);
        $t0 = microtime(true);
        try {
            $client->chat([], []);
            $this->fail('expected AI_BUSY');
        } catch (LlmUnavailableException $e) {
            $this->assertSame('AI_BUSY', $e->code_);
        }
        $waited = microtime(true) - $t0;
        $this->assertGreaterThanOrEqual(0.9, $waited, 'waited the bound (1 s) less one poll interval (50 ms)');
        $this->assertLessThan(2.0, $waited, 'the wait never exceeds the configured bound');
        $this->assertSame(0, $fake->calls, 'nothing was sent to the model');
        $this->assertSame(0, $this->c->interactiveWaiting(), 'waiting counter restored');
        $this->assertFalse(Cache::lock(ModelAccessCoordinator::lockKey(), 1)->get(), 'the in-flight holder still owns the lock');
        $held->release();
    }

    /** DEF09-05: a model timeout propagates as a controlled error, releases the lock, and no claims are fabricated. */
    public function test_def09_05_model_timeout_is_controlled_and_releases_the_lock(): void
    {
        $client = new CoordinatedLlmClient($this->fake(fn () => throw LlmUnavailableException::timeout()), $this->c);
        try {
            $client->chat([], []);
            $this->fail('expected AI_TIMEOUT');
        } catch (LlmUnavailableException $e) {
            $this->assertSame('AI_TIMEOUT', $e->code_);
        }
        $this->assertTrue(Cache::lock(ModelAccessCoordinator::lockKey(), 1)->get(), 'lock released after the failure');
        $this->assertSame(0, $this->c->interactiveWaiting());
        // retry policy: one bounded retry for a busy model only, never for a timeout or a schema failure
        $this->assertTrue(GenerateReportJob::shouldRetryForBusy(AiGenerationStatus::FAILED, 'AI_BUSY', 1, 2));
        $this->assertFalse(GenerateReportJob::shouldRetryForBusy(AiGenerationStatus::FAILED, 'AI_BUSY', 2, 2));
        $this->assertFalse(GenerateReportJob::shouldRetryForBusy(AiGenerationStatus::FAILED, 'AI_TIMEOUT', 1, 2));
        $this->assertFalse(GenerateReportJob::shouldRetryForBusy(AiGenerationStatus::SUCCEEDED, null, 1, 2));
    }

    /** DEF09-06: a crashed worker cannot leave a permanent lock (TTL) and an exception inside a request releases it. */
    public function test_def09_06_locks_expire_and_are_released_on_exceptions(): void
    {
        config()->set('dataspeak.ai.coordinator.interactive_wait_seconds', 3);
        $dead = Cache::lock(ModelAccessCoordinator::lockKey(), 1);
        $this->assertTrue($dead->get(), 'owner that will "crash" without releasing');
        $client = new CoordinatedLlmClient($fake = $this->fake(), $this->c);
        $t0 = microtime(true);
        $client->chat([], []); // waits until the 1 s TTL expires, then proceeds
        $this->assertSame(1, $fake->calls);
        $this->assertGreaterThan(0.5, microtime(true) - $t0);

        $thrower = new CoordinatedLlmClient($this->fake(fn () => throw new \RuntimeException('boom')), $this->c);
        try {
            $thrower->chat([], []);
        } catch (\RuntimeException) {
        }
        $this->assertTrue(Cache::lock(ModelAccessCoordinator::lockKey(), 1)->get(), 'lock released although the request threw');
    }

    /** DEF09-07: the coordinator is transparent: results pass through unchanged and nothing is called twice. */
    public function test_def09_07_results_pass_through_unchanged(): void
    {
        $client = new CoordinatedLlmClient($fake = $this->fake(), $this->c);
        $many = $client->chatMany([['messages' => [], 'schema' => []], ['messages' => [], 'schema' => []]]);
        $this->assertCount(2, $many);
        $this->assertSame(2, $fake->calls);
        $this->assertSame('fake', $client->modelTag());
        // disabled for API providers: straight pass-through, no lock taken
        config()->set('dataspeak.ai.driver', 'anthropic');
        $held = Cache::lock(ModelAccessCoordinator::lockKey(), 30);
        $held->get();
        $client->chat([], []);
        $this->assertSame(3, $fake->calls);
        $held->release();
    }

    /** DEF09-08: background work is never starved: under sustained interactive demand it proceeds after the yield bound. */
    public function test_def09_08_background_progresses_under_sustained_interactive_demand(): void
    {
        Cache::put(ModelAccessCoordinator::waitingKey(), 5, 60); // counter never drops in this test
        $client = new CoordinatedLlmClient($fake = $this->fake(), $this->c);
        $results = $this->c->asBackground(fn () => $client->chatMany([['messages' => [], 'schema' => []], ['messages' => [], 'schema' => []]]));
        $this->assertCount(2, $results);
        $this->assertSame(2, $fake->calls);
    }
}
