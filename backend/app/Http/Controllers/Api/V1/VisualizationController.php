<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStatus;
use App\Enums\ChartType;
use App\Enums\VisualizationSource;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\VisualizationResource;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Services\Reports\ChartCatalog;
use App\Services\Reports\ChartReasons;
use App\Support\ChartThemes;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MOD-08: VIS-API-001 GET /visualizations, VIS-API-002 GET /visualizations/options,
 * VIS-API-003 PUT /visualizations/selection (FEAT-VIS-003 chart review before the report is written).
 */
class VisualizationController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function __construct(private readonly AnalysisLifecycle $lifecycle, private readonly ChartCatalog $catalog) {}

    public function index(Request $request, string $analysis): AnonymousResourceCollection
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('view', $model);
        $this->lifecycle->assertAtLeast($model, AnalysisStatus::ANALYZED, 'Reading visualizations');

        return VisualizationResource::collection($model->visualizations()->with('insight')->orderBy('position')->get());
    }

    /** VIS-API-002: charts the calculated evidence can support that are not in the analysis yet. */
    public function options(Request $request, string $analysis): JsonResponse
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('view', $model);
        $this->lifecycle->assertAtLeast($model, AnalysisStatus::ANALYZED, 'Chart options');

        // The full specification travels with each option so the browser can draw the chart with its data before
        // the selection is saved (same shape as VIS-API-001 items; still aggregates, never rows).
        $options = array_map(fn ($o) => [
            'key' => $o['key'], 'type' => $o['type'], 'title' => $o['title'], 'x_label' => $o['x']['label'] ?? null, 'y_label' => $o['y']['label'] ?? null,
            'unit' => $o['y']['unit'] ?? null, 'points' => count($o['series']), 'evidence_ids' => $o['evidence_ids'], 'insight_rank' => $o['insight_rank'],
            'x' => $o['x'], 'y' => $o['y'], 'unit_missing' => $o['unit_missing'], 'series' => $o['series'],
            'reason' => ChartReasons::for($o, app()->getLocale()), // FEAT-VIS-006: why this chart fits the definitions
        ], $this->catalog->options($model));

        return response()->json(['data' => $options]);
    }

    /** VIS-API-003: keep/remove recommended charts and add chosen ones; the report is written from the kept set. */
    public function select(Request $request, string $analysis): AnonymousResourceCollection
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('update', $model);
        $this->lifecycle->assertAtLeast($model, AnalysisStatus::ANALYZED, 'Chart selection');
        $this->lifecycle->assertNotProcessing($model);
        $data = $request->validate([
            'selected' => ['present', 'array'], 'selected.*' => ['string', 'max:32'],
            'add' => ['sometimes', 'array', 'max:20'], 'add.*' => ['string', 'max:80'],
            'theme' => ['sometimes', 'string', 'in:'.implode(',', ChartThemes::keys())], // FEAT-VIS-004 colour theme
        ]);
        $selected = array_map('strval', $data['selected']);
        $add = array_values(array_unique(array_map('strval', $data['add'] ?? [])));

        $built = [];
        foreach ($add as $key) {
            $option = $this->catalog->build($model, $key);
            if ($option === null) {
                throw ApiException::unprocessable('UNKNOWN_CHART_OPTION', 'One of the charts to add is not available for this analysis.', [['field' => 'add', 'issue' => "Unknown chart option: {$key}"]]);
            }
            $built[] = $option;
        }
        $keptCount = $model->visualizations()->whereIn('id', array_filter($selected, 'is_numeric'))->count() + count($built);
        if ($keptCount === 0) {
            throw ApiException::unprocessable('NO_CHARTS_SELECTED', 'Keep or add at least one chart for the report.');
        }

        DB::transaction(function () use ($model, $selected, $built, $data): void {
            if (isset($data['theme'])) {
                $model->forceFill(['chart_theme' => ChartThemes::normalize($data['theme'])])->save();
            }
            foreach ($model->visualizations()->get() as $chart) {
                $chart->forceFill(['is_selected' => in_array((string) $chart->id, $selected, true)])->save();
            }
            $position = (int) $model->visualizations()->max('position');
            $insightIds = $model->insights()->pluck('id', 'rank')->all();
            foreach ($built as $option) {
                $position++;
                $model->visualizations()->create([
                    'insight_id' => $insightIds[$option['insight_rank'] ?? -1] ?? null,
                    'chart_type' => ChartType::from($option['type']),
                    'source' => VisualizationSource::USER,
                    'title' => mb_substr($option['title'], 0, 255),
                    'spec' => ['x' => $option['x'], 'y' => $option['y'], 'unit_missing' => $option['unit_missing'], 'series' => $option['series']],
                    'evidence_keys' => $option['evidence_ids'],
                    'position' => $position,
                    'is_selected' => true,
                ]);
            }
        });
        Log::info('visualizations.selected', ['analysis_id' => $model->public_id, 'selected' => $keptCount, 'added' => count($built), 'theme' => $model->fresh()->chart_theme]);

        return VisualizationResource::collection($model->visualizations()->with('insight')->orderBy('position')->get());
    }
}
