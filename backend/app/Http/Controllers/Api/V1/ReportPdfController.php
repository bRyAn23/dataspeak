<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStatus;
use App\Exceptions\LifecycleConflictException;
use App\Http\Controllers\Controller;
use App\Services\Reports\Pdf\ReportPdfBuilder;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/** RPT-API-003 (extension of the Laravel API doc): GET /analyses/{id}/report/pdf (FEAT-RPT-003). */
class ReportPdfController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function __invoke(Request $request, string $analysis, ReportPdfBuilder $builder): Response
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('view', $model);

        $report = $model->report;
        if ($report === null || $model->status !== AnalysisStatus::COMPLETED) {
            throw new LifecycleConflictException('The report has not been generated yet.', 'STEP_NOT_READY');
        }

        $started = microtime(true);
        $bytes = $builder->build($report, $request);
        Log::info('report.pdf', ['analysis_id' => $model->public_id, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Language' => app()->getLocale(), // the interface language the PDF was typeset in
            'Content-Disposition' => 'attachment; filename="'.ReportPdfBuilder::fileName($report).'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
