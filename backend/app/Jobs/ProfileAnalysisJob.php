<?php

namespace App\Jobs;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Enums\JobStatus;
use App\Exceptions\AnalyticsServiceException;
use App\Models\Analysis;
use App\Services\Ai\ColumnDefinitionSuggester;
use App\Services\Analytics\AnalyticsServiceClient;
use App\Services\Analytics\DefinitionPayload;
use App\Services\Analytics\ProfilePersister;
use App\Services\Analytics\ProgressStore;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Services\Uploads\TransientUploadStore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PROF-API-001 as a queued job so the browser can follow real progress (RUN-API-002):
 *   PROFILING  - analytics service profiles the CSV, derived columns are persisted
 *   DEFINITION - suggested definitions (English name, meaning, unit, aggregation; FEAT-DEF-003)
 * On failure the analysis stays VALIDATED with the file kept, and the job record carries a safe error so the
 * Profile page can explain and offer a retry.
 */
class ProfileAnalysisJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $analysisId, public readonly string $jobId)
    {
        $this->timeout = (int) config('dataspeak.profile_job_timeout_seconds', 300);
    }

    public function uniqueId(): string
    {
        return 'profile:'.$this->analysisId;
    }

    public function handle(
        AnalyticsServiceClient $client,
        AnalysisLifecycle $lifecycle,
        TransientUploadStore $uploads,
        ProgressStore $progress,
        ProfilePersister $persister,
        ColumnDefinitionSuggester $suggester,
    ): void {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null || $analysis->status !== AnalysisStatus::VALIDATED) {
            return; // deleted, re-uploaded or already profiled
        }

        $stage = $lifecycle->beginStage($analysis, AnalysisStage::PROFILING);
        $current = AnalysisStage::PROFILING;
        $progress->update($analysis, JobStatus::RUNNING, $current->value, 10);

        try {
            if (! $analysis->hasTransientFile() || ! $uploads->exists($analysis)) {
                throw new \RuntimeException('UPLOAD_REQUIRED');
            }
            $profile = $client->profile($uploads->absolutePath($analysis), $analysis->public_id, DefinitionPayload::for($analysis));
            $progress->update($analysis, JobStatus::RUNNING, $current->value, 45);
            $persister->persist($analysis, $profile);

            $current = AnalysisStage::DEFINITION;
            $progress->update($analysis, JobStatus::RUNNING, $current->value, 55);
            $suggestions = $suggester->apply($analysis);
            $progress->update($analysis, JobStatus::RUNNING, $current->value, 95);

            $lifecycle->transition($analysis, AnalysisStatus::PROFILED, AnalysisStage::PROFILING);
            $lifecycle->completeStage($stage, ['column_count' => $analysis->column_count, 'row_count' => $analysis->row_count, 'suggestions' => $suggestions]);
            $progress->update($analysis, JobStatus::SUCCEEDED, AnalysisStage::PROFILING->value, 100);
        } catch (Throwable $e) {
            [$code, $message] = match (true) {
                $e instanceof AnalyticsServiceException => [$e->errorCode, $e->getMessage()],
                $e->getMessage() === 'UPLOAD_REQUIRED' => ['UPLOAD_REQUIRED', 'The uploaded dataset is no longer available. Upload the file again.'],
                default => ['PROFILING_FAILED', 'Profiling could not be completed.'],
            };
            $lifecycle->failStage($stage, $code);
            $job = $progress->get($analysis);
            $progress->update($analysis, JobStatus::FAILED, $current->value, (int) ($job['progress'] ?? 0), ['code' => $code, 'message' => $message]);
            Log::error('analysis.profile_failed', ['analysis_id' => $analysis->public_id, 'code' => $code, 'method' => $e::class]);
        }
    }

    /** Hard timeout or lost worker: the job record must not stay RUNNING. */
    public function failed(?Throwable $e = null): void
    {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null) {
            return;
        }
        $progress = app(ProgressStore::class);
        if ($progress->isActive($analysis, 'profile')) {
            $progress->update($analysis, JobStatus::FAILED, AnalysisStage::PROFILING->value, 0, ['code' => 'JOB_INTERRUPTED', 'message' => 'Profiling stopped before it finished. Please try again.']);
            Log::error('analysis.profile_job_failed', ['analysis_id' => $analysis->public_id, 'method' => $e ? $e::class : null]);
        }
    }
}
