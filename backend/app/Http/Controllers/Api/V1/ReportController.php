<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Enums\JobStatus;
use App\Exceptions\LifecycleConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Jobs\GenerateReportJob;
use App\Services\Analytics\ProgressStore;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Support\Locale;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** MOD-11 Executive Reporting: RPT-API-001 (queue generation, 202) and RPT-API-002 (read). */
class ReportController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function __construct(
        private readonly AnalysisLifecycle $lifecycle,
        private readonly ProgressStore $progress,
    ) {}

    /** RPT-API-001 POST /analyses/{id}/report -> 202; poll RUN-API-002 until COMPLETED */
    public function generate(Request $request, string $analysis): JsonResponse
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('update', $model);
        $this->lifecycle->assertCanBuildReport($model); // ANALYZED/COMPLETED, or FAILED in the report phase
        $this->lifecycle->assertNotProcessing($model);
        $request->validate(['language' => ['sometimes', 'string', Rule::in(Locale::SUPPORTED)]]);
        $language = Locale::normalize($request->input('language'), app()->getLocale()); // FEAT-I18N-003

        $model->report_language = $language;
        $this->lifecycle->transition($model, AnalysisStatus::REPORTING, AnalysisStage::AI_SYNTHESIS);
        $jobId = 'job_'.Str::uuid();
        $this->progress->start($model, $jobId);
        GenerateReportJob::dispatch($model->id, $jobId, $language);

        return response()->json(['data' => [
            'analysis_id' => $model->public_id,
            'job_id' => $jobId,
            'status' => JobStatus::QUEUED->value,
        ]], 202);
    }

    /** RPT-API-002 GET /analyses/{id}/report */
    public function show(Request $request, string $analysis): ReportResource
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('view', $model);

        $report = $model->report;
        if ($report === null || $model->status === AnalysisStatus::REPORTING) {
            throw new LifecycleConflictException('The report has not been generated yet.', 'STEP_NOT_READY');
        }

        return new ReportResource($report->load('analysis'));
    }
}
