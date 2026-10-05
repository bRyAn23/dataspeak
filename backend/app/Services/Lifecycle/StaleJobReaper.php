<?php

namespace App\Services\Lifecycle;

use App\Enums\AnalysisStatus;
use App\Enums\JobStatus;
use App\Enums\StageStatus;
use App\Models\Analysis;
use App\Services\Analytics\ProgressStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * NFR-REL-001 safety net for background jobs that die without reporting back (worker restart, SIGKILL,
 * container stop, hard timeout). A processing analysis (ANALYZING/REPORTING) whose running stage is older
 * than the job timeout plus a grace period is marked FAILED with JOB_INTERRUPTED, which unblocks the UI and
 * lets the user run the step again. Called from the status endpoint, the queue failure hooks and a
 * scheduled command.
 */
class StaleJobReaper
{
    public const CODE = 'JOB_INTERRUPTED';

    public const MESSAGE = 'This step stopped before it finished, for example because the background worker was restarted. Nothing already calculated is lost; you can run it again.';

    public function __construct(
        private readonly AnalysisLifecycle $lifecycle,
        private readonly ProgressStore $progress,
    ) {}

    public function deadlineSeconds(): int
    {
        return (int) config('dataspeak.ai.job_timeout_seconds', 900) + (int) config('dataspeak.stale_job_grace_seconds', 120);
    }

    /** Marks the analysis interrupted when its running stage is older than the deadline (or always with $force). */
    public function reap(Analysis $analysis, bool $force = false, string $code = self::CODE): bool
    {
        if (! $analysis->status->isProcessing()) {
            return false;
        }

        $running = $analysis->stages()->where('status', StageStatus::RUNNING->value)->orderByDesc('started_at')->first();
        $since = $running?->started_at ?? $analysis->updated_at;
        if (! $force && $since !== null && $since->gt(now()->subSeconds($this->deadlineSeconds()))) {
            return false;
        }

        if ($running !== null) {
            $this->lifecycle->failStage($running, $code);
        }
        $stage = $running?->stage ?? $analysis->current_stage;
        $this->lifecycle->fail($analysis, $stage, $code, self::MESSAGE);
        $this->progress->update($analysis, JobStatus::FAILED, $stage->value, (int) ($this->progress->get($analysis)['progress'] ?? 0));

        Log::warning('analysis.job_interrupted', ['analysis_id' => $analysis->public_id, 'stage' => $stage->value, 'forced' => $force, 'code' => $code]);

        return true;
    }

    /**
     * A profiling job (PROF-API-001) that stopped reporting: the analysis keeps VALIDATED (the file is still
     * there), only the job record is marked FAILED so the UI stops waiting and offers a retry.
     */
    public function reapProfiling(Analysis $analysis): bool
    {
        if (! $this->progress->isActive($analysis, 'profile')) {
            return false;
        }
        $job = $this->progress->get($analysis);
        $deadline = (int) config('dataspeak.profile_job_timeout_seconds', 300) + (int) config('dataspeak.stale_job_grace_seconds', 120);
        $updated = isset($job['updated_at']) ? Carbon::parse($job['updated_at']) : null;
        if ($updated === null || $updated->gt(now()->subSeconds($deadline))) {
            return false;
        }
        $this->progress->update($analysis, JobStatus::FAILED, $job['stage'] ?? 'PROFILING', (int) ($job['progress'] ?? 0), ['code' => self::CODE, 'message' => 'Profiling stopped before it finished. Please try again.']);
        Log::warning('analysis.profile_job_interrupted', ['analysis_id' => $analysis->public_id]);

        return true;
    }

    /** Sweeps every processing analysis; returns how many were marked interrupted. */
    public function reapAll(bool $force = false, ?string $publicId = null): int
    {
        $query = Analysis::query()->whereIn('status', [AnalysisStatus::ANALYZING->value, AnalysisStatus::REPORTING->value]);
        if ($publicId !== null) {
            $query->where('public_id', $publicId);
        }

        $count = 0;
        foreach ($query->cursor() as $analysis) {
            $count += $this->reap($analysis, $force) ? 1 : 0;
        }

        return $count;
    }
}
