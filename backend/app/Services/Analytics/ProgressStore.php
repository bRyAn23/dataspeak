<?php

namespace App\Services\Analytics;

use App\Enums\JobStatus;
use App\Models\Analysis;
use Illuminate\Support\Facades\Cache;

/**
 * Job metadata for RUN-API-002 polling, kept in the cache store (Redis in production).
 * Holds only job id, status, stage and progress; never dataset content.
 */
class ProgressStore
{
    public function key(Analysis $analysis): string
    {
        return 'dataspeak:analysis:'.$analysis->public_id.':job';
    }

    /** @param  string  $kind  'run' (RUN-API-001, chained report) or 'profile' (PROF-API-001) */
    public function start(Analysis $analysis, string $jobId, string $kind = 'run'): void
    {
        $this->put($analysis, ['job_id' => $jobId, 'kind' => $kind, 'status' => JobStatus::QUEUED->value, 'stage' => null, 'progress' => 0, 'error' => null]);
    }

    /** @param  array{code: string, message: string}|null  $error  safe code and message, never cell values */
    public function update(Analysis $analysis, JobStatus $status, ?string $stage, int $progress, ?array $error = null): void
    {
        $current = $this->get($analysis) ?? [];
        $this->put($analysis, [
            'job_id' => $current['job_id'] ?? null,
            'kind' => $current['kind'] ?? 'run',
            'status' => $status->value,
            'stage' => $stage,
            'progress' => max(0, min(100, $progress)),
            'error' => $status === JobStatus::FAILED ? $error : null,
        ]);
    }

    /** True while a job of this kind is queued or running. */
    public function isActive(Analysis $analysis, ?string $kind = null): bool
    {
        $job = $this->get($analysis);

        return $job !== null
            && in_array($job['status'], [JobStatus::QUEUED->value, JobStatus::RUNNING->value], true)
            && ($kind === null || ($job['kind'] ?? 'run') === $kind);
    }

    /** @return array{job_id: ?string, kind?: string, status: string, stage: ?string, progress: int, error?: ?array, updated_at: string}|null */
    public function get(Analysis $analysis): ?array
    {
        $value = Cache::get($this->key($analysis));

        return is_array($value) ? $value : null;
    }

    public function clear(Analysis $analysis): void
    {
        Cache::forget($this->key($analysis));
    }

    private function put(Analysis $analysis, array $data): void
    {
        Cache::put($this->key($analysis), $data + ['updated_at' => now()->toIso8601String()], now()->addDay());
    }
}
