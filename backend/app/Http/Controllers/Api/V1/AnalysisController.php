<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnalysisRequest;
use App\Http\Resources\AnalysisDetailResource;
use App\Http\Resources\AnalysisResource;
use App\Models\Analysis;
use App\Services\AnalysisDeletionService;
use App\Services\Analytics\ProgressStore;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/** MOD-02 Analysis Management: ANL-API-001..004 */
class AnalysisController extends Controller
{
    use ResolvesOwnedAnalysis;

    /** ANL-API-001 GET /analyses */
    public function index(Request $request): AnonymousResourceCollection
    {
        $analyses = Analysis::query()
            ->ownedBy($request->user())
            ->latest('created_at')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return AnalysisResource::collection($analyses);
    }

    /** ANL-API-002 POST /analyses */
    public function store(StoreAnalysisRequest $request): JsonResponse
    {
        $analysis = $request->user()->analyses()->create(['name' => $request->string('name')]);
        Log::info('analysis.created', ['analysis_id' => $analysis->public_id]);

        return (new AnalysisResource($analysis))->response()->setStatusCode(201);
    }

    /** ANL-API-003 GET /analyses/{id} */
    public function show(Request $request, string $analysis, ProgressStore $progress): AnalysisDetailResource
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('view', $model);
        $model->load('stages');

        return new AnalysisDetailResource($model, $progress->get($model));
    }

    /** ANL-API-004 DELETE /analyses/{id} */
    public function destroy(Request $request, string $analysis, AnalysisDeletionService $deleter): Response
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('delete', $model);
        $deleter->delete($model);

        return response()->noContent();
    }
}
