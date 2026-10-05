<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Exceptions\AnalyticsServiceException;
use App\Exceptions\ApiException;
use App\Exceptions\LifecycleConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDatasetRequest;
use App\Http\Resources\AnalysisResource;
use App\Models\Analysis;
use App\Services\Analytics\AnalysisResultPersister;
use App\Services\Analytics\AnalyticsServiceClient;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Services\Uploads\TransientUploadStore;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** MOD-03 CSV Intake: DATA-API-001 (transient upload; .xlsx converted to CSV) and DATA-API-002 (validate through analytics). */
class DatasetController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function __construct(
        private readonly AnalysisLifecycle $lifecycle,
        private readonly TransientUploadStore $uploads,
        private readonly AnalyticsServiceClient $analytics,
        private readonly AnalysisResultPersister $results,
    ) {}

    /** DATA-API-001 POST /analyses/{id}/dataset  (multipart, never logged) */
    public function upload(UploadDatasetRequest $request, string $analysis): JsonResponse
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('update', $model);
        $this->lifecycle->assertNotProcessing($model);

        DB::transaction(function () use ($model): void {
            // A new upload invalidates every derived artefact of the previous one.
            $this->results->clear($model);
            $columnIds = $model->columns()->pluck('id');
            DB::table('field_definitions')->whereIn('dataset_column_id', $columnIds)->delete();
            $model->columns()->delete();
            $model->forceFill(['row_count' => null, 'column_count' => null, 'completeness_pct' => null]);
        });

        $stage = $this->lifecycle->beginStage($model, AnalysisStage::UPLOAD);
        try {
            $this->uploads->store($request->file('file'), $model);
        } catch (ApiException $e) {
            $this->lifecycle->failStage($stage, $e->errorCode); // e.g. SPREADSHEET_UNREADABLE; the analysis keeps its state
            throw $e;
        }
        $conversion = $this->uploads->lastConversion;
        $this->lifecycle->transition($model, AnalysisStatus::UPLOADED, AnalysisStage::UPLOAD);
        $this->lifecycle->completeStage($stage, ['size_bytes' => $model->source_file_size_bytes] + ($conversion ? array_diff_key($conversion, ['sheet' => 1]) : []));

        $source = $conversion
            ? ['format' => 'xlsx', 'sheet' => $conversion['sheet'], 'sheets_total' => $conversion['sheets_total'], 'rows' => $conversion['row_count'], 'columns' => $conversion['column_count']]
            : ['format' => 'csv'];

        return (new AnalysisResource($model->refresh()))->additional(['meta' => ['source' => $source]])->response()->setStatusCode(201);
    }

    /** DATA-API-002 POST /analyses/{id}/validate */
    public function validate(Request $request, string $analysis): JsonResponse
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('update', $model);
        $this->lifecycle->assertAtLeast($model, AnalysisStatus::UPLOADED, 'Validation');
        $this->lifecycle->assertNotProcessing($model);
        $this->requireTransientFile($model);

        $stage = $this->lifecycle->beginStage($model, AnalysisStage::VALIDATION);

        try {
            $result = $this->analytics->validate($this->uploads->absolutePath($model), $model->public_id);
        } catch (AnalyticsServiceException $e) {
            $this->lifecycle->failStage($stage, $e->errorCode);
            throw $e;
        }

        $valid = (bool) ($result['valid'] ?? false);
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];

        if (! $valid) {
            $this->lifecycle->failStage($stage, 'CSV_VALIDATION_FAILED');
            throw ApiException::unprocessable(
                'CSV_VALIDATION_FAILED',
                'The dataset could not be validated.',
                array_map(fn ($e) => ['field' => $e['column'] ?? 'file', 'issue' => (string) ($e['message'] ?? $e['code'] ?? 'invalid')], $errors),
            );
        }

        $model->forceFill([
            'row_count' => isset($result['row_count']) ? (int) $result['row_count'] : null,
            'column_count' => isset($result['column_count']) ? (int) $result['column_count'] : null,
        ]);
        $this->lifecycle->transition($model, AnalysisStatus::VALIDATED, AnalysisStage::VALIDATION);
        $this->lifecycle->completeStage($stage, ['row_count' => $model->row_count, 'column_count' => $model->column_count, 'warning_count' => count($warnings)]);

        return response()->json(['data' => [
            'analysis_id' => $model->public_id,
            'status' => $model->status->value,
            'valid' => true,
            'row_count' => $model->row_count,
            'column_count' => $model->column_count,
            'warnings' => $warnings,
            'errors' => [],
        ]]);
    }

    private function requireTransientFile(Analysis $model): void
    {
        if (! $model->hasTransientFile() || ! $this->uploads->exists($model)) {
            throw new LifecycleConflictException('The uploaded dataset is no longer available. Upload the CSV again.', 'UPLOAD_REQUIRED');
        }
    }
}
