<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Enums\JobStatus;
use App\Exceptions\LifecycleConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RunAnalysisRequest;
use App\Jobs\RunAnalysisJob;
use App\Services\Analytics\ProgressStore;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Services\Lifecycle\StaleJobReaper;
use App\Services\Uploads\TransientUploadStore;
use App\Support\Locale;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** MOD-02/06 orchestration: RUN-API-001 (queue analysis) and RUN-API-002 (status for polling). */
class RunController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function __construct(
        private readonly AnalysisLifecycle $lifecycle,
        private readonly TransientUploadStore $uploads,
        private readonly ProgressStore $progress,
        private readonly StaleJobReaper $reaper,
    ) {}

    /** RUN-API-001 POST /analyses/{id}/run -> 202 */
    public function run(RunAnalysisRequest $request, string $analysis): JsonResponse
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('update', $model);
        $this->lifecycle->assertNotProcessing($model);

        $defined = $model->columns()->whereHas('definition')->exists();
        if (! $defined || ($model->status !== AnalysisStatus::FAILED && ! $model->status->atLeast(AnalysisStatus::DEFINED))) {
            throw new LifecycleConflictException('Save field definitions before running the analysis.', 'STEP_NOT_READY');
        }
        if (! $model->hasTransientFile() || ! $this->uploads->exists($model)) {
            throw new LifecycleConflictException('The uploaded dataset is no longer available. Upload the CSV again to run the analysis.', 'UPLOAD_REQUIRED');
        }

        // FEAT-I18N-003: the report (narrative, labels, PDF) is produced in the language the user is working in.
        $model->report_language = Locale::normalize($request->input('language'), app()->getLocale());
        $this->lifecycle->transition($model, AnalysisStatus::ANALYZING, AnalysisStage::STATISTICAL_ANALYSIS);

        $jobId = 'job_'.Str::uuid();
        $this->progress->start($model, $jobId);
        RunAnalysisJob::dispatch($model->id, $jobId);

        return response()->json(['data' => [
            'analysis_id' => $model->public_id,
            'job_id' => $jobId,
            'status' => JobStatus::QUEUED->value,
        ]], 202);
    }

    /** RUN-API-002 GET /analyses/{id}/status */
    public function status(Request $request, string $analysis): JsonResponse
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('view', $model);
        if ($this->reaper->reap($model)) {
            $model->refresh(); // an interrupted job is reported as FAILED instead of spinning forever
        }
        $this->reaper->reapProfiling($model);
        $job = $this->progress->get($model);
        $jobActive = $job !== null && in_array($job['status'], [JobStatus::QUEUED->value, JobStatus::RUNNING->value], true);

        $progress = match (true) {
            $jobActive => (int) $job['progress'],
            $job !== null && $model->status->isProcessing() => (int) $job['progress'],
            $job !== null && $job['status'] === JobStatus::SUCCEEDED->value => 100,
            $model->status->atLeast(AnalysisStatus::ANALYZED) => 100,
            $model->status === AnalysisStatus::FAILED => (int) ($job['progress'] ?? 0),
            default => 0,
        };

        return response()->json(['data' => [
            'analysis_id' => $model->public_id,
            'status' => $model->status->value,
            'stage' => $model->current_stage->value,
            'progress' => $progress,
            'job' => $job,
            'is_terminal' => $model->status->isTerminal(),
            'last_error' => $model->last_error_code ? ['code' => $model->last_error_code, 'message' => $model->last_error_message] : null,
            'poll_seconds' => (int) config('dataspeak.status_poll_seconds', 2),
        ]]);
    }
}
