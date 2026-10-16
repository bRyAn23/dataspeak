<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * DEF-09: a single local model (Ollama) serves one generation at a time, so a long background translation
 * request can hold every user-facing narrative request behind it until the HTTP timeout fires.
 *
 * This coordinator serialises model requests application-side with a Redis (cache) lock and gives
 * INTERACTIVE requests (narrative, column suggestions, on-demand translation) bounded priority over
 * BACKGROUND requests (pre-translation):
 *  - an interactive request raises a "waiting" counter while it waits for and holds the lock;
 *  - a background request first yields while that counter is above zero, but only for a bounded time, then
 *    competes for the lock normally, so background work can never starve;
 *  - every wait is bounded and a wait that runs out raises LlmUnavailableException::busy() (code AI_BUSY),
 *    which callers already treat as "model unavailable" (deterministic fallback, bounded job retry);
 *  - the lock carries a TTL and is released in finally, so a crashed worker cannot leave it behind;
 *  - a request that is already running is never interrupted to make room for another.
 * Disabled automatically for API providers that handle concurrency themselves (see config dataspeak.ai.coordinator).
 */
class ModelAccessCoordinator
{
    public const INTERACTIVE = 'interactive';

    public const BACKGROUND = 'background';

    private string $context = self::INTERACTIVE;

    public function enabled(): bool
    {
        $mode = config('dataspeak.ai.coordinator.enabled', 'auto');
        if ($mode === 'auto' || $mode === null) {
            return config('dataspeak.ai.driver') === 'ollama';
        }

        return filter_var($mode, FILTER_VALIDATE_BOOL);
    }

    public function context(): string
    {
        return $this->context;
    }

    /** Run $fn with every model request inside it classified as background work. */
    public function asBackground(callable $fn): mixed
    {
        return $this->withContext(self::BACKGROUND, $fn);
    }

    /** Run $fn with every model request inside it classified as interactive (user-facing) work. */
    public function asInteractive(callable $fn): mixed
    {
        return $this->withContext(self::INTERACTIVE, $fn);
    }

    private function withContext(string $context, callable $fn): mixed
    {
        $previous = $this->context;
        $this->context = $context;
        try {
            return $fn();
        } finally {
            $this->context = $previous;
        }
    }

    /**
     * Execute one model request under the coordinator. Returns the request's result unchanged.
     *
     * @throws LlmUnavailableException with code AI_BUSY when the bounded wait for the model runs out
     */
    public function run(callable $request, ?string $class = null): mixed
    {
        if (! $this->enabled()) {
            return $request();
        }
        $class ??= $this->context;
        $cfg = (array) config('dataspeak.ai.coordinator');
        $lockTtl = max(5, (int) ($cfg['lock_ttl_seconds'] ?? 330));
        $wait = $class === self::INTERACTIVE ? (int) ($cfg['interactive_wait_seconds'] ?? 240) : (int) ($cfg['background_wait_seconds'] ?? 300);
        $pollMs = max(50, (int) ($cfg['poll_ms'] ?? 250));

        if ($class === self::INTERACTIVE) {
            $this->changeWaiting(+1, $lockTtl + $wait);
        }
        try {
            if ($class === self::BACKGROUND) {
                $this->yieldToInteractive((int) ($cfg['background_yield_seconds'] ?? 120), $pollMs);
            }
            // The lock polls every poll_ms and gives up one interval before $wait, so the wait never exceeds the bound.
            $lock = Cache::lock(self::lockKey(), $lockTtl)->betweenBlockedAttemptsSleepFor($pollMs);
            $started = microtime(true);
            try {
                $lock->block($wait, null);
            } catch (LockTimeoutException) {
                Log::warning('llm.lock.timeout', ['class' => $class, 'waited_s' => $wait]);
                throw LlmUnavailableException::busy($class, $wait);
            }
            $waitedMs = (int) ((microtime(true) - $started) * 1000);
            if ($waitedMs > 200) {
                Log::info('llm.lock.waited', ['class' => $class, 'waited_ms' => $waitedMs]);
            }
            try {
                return $request();
            } finally {
                $lock->release();
            }
        } finally {
            if ($class === self::INTERACTIVE) {
                $this->changeWaiting(-1, $lockTtl + $wait);
            }
        }
    }

    /** Background requests step aside while interactive requests are waiting, for at most $seconds. */
    private function yieldToInteractive(int $seconds, int $pollMs): void
    {
        $deadline = microtime(true) + max(0, $seconds);
        $cycles = 0;
        while ($this->interactiveWaiting() > 0 && microtime(true) < $deadline) {
            usleep($pollMs * 1000);
            $cycles++;
        }
        if ($cycles > 0) {
            Log::info('llm.background.yielded', ['cycles' => $cycles, 'proceeding_after_bound' => $this->interactiveWaiting() > 0]);
        }
    }

    public function interactiveWaiting(): int
    {
        return max(0, (int) Cache::get(self::waitingKey(), 0));
    }

    private function changeWaiting(int $delta, int $ttlSeconds): void
    {
        $key = self::waitingKey();
        if ($delta > 0) {
            Cache::add($key, 0, $ttlSeconds);
            Cache::increment($key, $delta);

            return;
        }
        $value = (int) Cache::get($key, 0) + $delta;
        if ($value <= 0) {
            Cache::forget($key);
        } else {
            Cache::decrement($key, -$delta);
        }
    }

    public static function lockKey(): string
    {
        return 'llm:model:'.md5((string) config('dataspeak.ai.driver').':'.(string) config('services.ollama.model'));
    }

    public static function waitingKey(): string
    {
        return 'llm:interactive_waiting:'.md5((string) config('dataspeak.ai.driver').':'.(string) config('services.ollama.model'));
    }
}
