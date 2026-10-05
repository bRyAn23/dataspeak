<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InsightResource;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** MOD-07: INS-API-001 GET /analyses/{id}/insights */
class InsightController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function __construct(private readonly AnalysisLifecycle $lifecycle) {}

    public function index(Request $request, string $analysis): AnonymousResourceCollection
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('view', $model);
        $this->lifecycle->assertAtLeast($model, AnalysisStatus::ANALYZED, 'Reading insights');

        $resolver = PlaceholderResolver::for($model);

        return InsightResource::collection($model->insights()->with('evidence.metricColumn.definition', 'evidence.dimensionColumn.definition')->get()->map(fn ($i) => new InsightResource($i, $resolver)));
    }
}
