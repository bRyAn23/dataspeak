<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStatus;
use App\Http\Controllers\Controller;
use App\Services\Ai\ColumnDefinitionSuggester;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DEF-API-002 POST /analyses/{id}/definitions/suggest-names: re-run the definition suggestions (English name,
 * meaning, unit, aggregation) on demand. Nothing is stored; the Define page applies what the user accepts.
 */
class DefinitionSuggestionController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function __invoke(Request $request, string $analysis, AnalysisLifecycle $lifecycle, ColumnDefinitionSuggester $suggester): JsonResponse
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('update', $model);
        $lifecycle->assertAtLeast($model, AnalysisStatus::PROFILED, 'Definition suggestions');

        $columns = $model->columns()->orderBy('position')->get()
            ->map(fn ($c) => [
                'name' => $c->source_name,
                'inferred_type' => $c->inferred_type?->value,
                'distinct_count' => $c->distinct_count,
                'null_rate' => (float) $c->null_rate,
                'stats' => array_intersect_key((array) ($c->profile_stats ?? []), array_flip(['min', 'max', 'mean'])),
            ])->all();
        $result = $suggester->suggest($columns, $model->name);

        return response()->json([
            'data' => $result['items'],
            'meta' => [
                'ai_status' => $result['ai_status'],
                'model' => $result['model'],
                'prompt_version' => ColumnDefinitionSuggester::PROMPT_VERSION,
                'ai_count' => $result['ai_count'],
            ],
        ]);
    }
}
