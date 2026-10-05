<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStatus;
use App\Enums\JobStatus;
use App\Exceptions\LifecycleConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Jobs\ProfileAnalysisJob;
use App\Services\Analytics\ProgressStore;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Services\Lifecycle\StaleJobReaper;
use App\Services\Uploads\TransientUploadStore;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** MOD-04 Profiling orchestration: PROF-API-001 (run) and PROF-API-002 (read stored profile). */
class ProfileController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function __construct(
        private readonly AnalysisLifecycle $lifecycle,
        private readonly TransientUploadStore $uploads,
        private readonly ProgressStore $progress,
        private readonly StaleJobReaper $reaper,
    ) {}

    /** PROF-API-001 POST /analyses/{id}/profile -> 202; progress through RUN-API-002 (stages PROFILING, DEFINITION). */
    public function run(Request $request, string $analysis): JsonResponse
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('update', $model);
        $this->lifecycle->assertAtLeast($model, AnalysisStatus::VALIDATED, 'Profiling');
        $this->lifecycle->assertNotProcessing($model);

        if (! $model->hasTransientFile() || ! $this->uploads->exists($model)) {
            throw new LifecycleConflictException('The uploaded dataset is no longer available. Upload the CSV again.', 'UPLOAD_REQUIRED');
        }

        // Idempotent while a profiling job is already queued or running (page refresh, double click).
        $job = $this->progress->get($model);
        if ($model->status === AnalysisStatus::VALIDATED && $this->progress->isActive($model, 'profile') && ! $this->reaper->reapProfiling($model)) {
            return response()->json(['data' => ['analysis_id' => $model->public_id, 'job_id' => $job['job_id'], 'status' => $job['status']]], 202);
        }

        $jobId = 'job_'.Str::uuid();
        $this->progress->start($model, $jobId, 'profile');
        ProfileAnalysisJob::dispatch($model->id, $jobId);

        return response()->json(['data' => [
            'analysis_id' => $model->public_id,
            'job_id' => $jobId,
            'status' => JobStatus::QUEUED->value,
        ]], 202);
    }

    /** PROF-API-002 GET /analyses/{id}/profile */
    public function show(Request $request, string $analysis): ProfileResource
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('view', $model);

        if ($model->columns()->doesntExist()) {
            throw new LifecycleConflictException('The dataset has not been profiled yet.', 'STEP_NOT_READY');
        }

        return new ProfileResource($model->load('columns.definition'));
    }
}
