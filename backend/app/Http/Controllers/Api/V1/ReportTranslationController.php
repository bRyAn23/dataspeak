<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStatus;
use App\Exceptions\LifecycleConflictException;
use App\Http\Controllers\Controller;
use App\Jobs\TranslateReportJob;
use App\Services\Ai\ClaimTranslator;
use App\Services\Ai\DisplayLabelTranslator;
use App\Services\Reports\DisplayLabels;
use App\Support\Locale;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RPT-API-004 POST /analyses/{id}/report/translate: show the existing verified report in another language.
 * Labels and category values get their map for that language, then the narrative statements are translated
 * (never re-interpreted). Synchronous: typically ten to thirty seconds with a local model.
 */
class ReportTranslationController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function __invoke(Request $request, string $analysis, DisplayLabelTranslator $labels, ClaimTranslator $claims): JsonResponse
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('view', $model);
        if ($model->status !== AnalysisStatus::COMPLETED || $model->report === null) {
            throw new LifecycleConflictException('The report has not been generated yet.', 'STEP_NOT_READY');
        }
        $data = $request->validate(['language' => ['required', 'string', Rule::in(Locale::SUPPORTED)]]);
        $locale = Locale::normalize($data['language']);
        if (TranslateReportJob::inProgress($model, $locale)) {
            return response()->json(['data' => ['language' => $locale, 'status' => 'in_progress']], 202); // the page polls the report
        }
        set_time_limit(300);

        $labelResult = isset(DisplayLabels::normalizeStored($model->label_translations)[$locale]) ? ['ai_status' => 'cached'] : $labels->translate($model, $locale);
        $claimResult = $claims->translate($model->fresh(), $locale);

        return response()->json(['data' => [
            'language' => $locale,
            'report_language' => Locale::normalize($model->report->language ?? 'en'),
            'translated' => $claimResult['translated'],
            'pending' => $claimResult['pending'],
            'total' => $claimResult['total'],
            'ai_status' => $claimResult['ai_status'],
            'labels_ai_status' => $labelResult['ai_status'],
            'model' => $claimResult['model'],
        ]]);
    }
}
