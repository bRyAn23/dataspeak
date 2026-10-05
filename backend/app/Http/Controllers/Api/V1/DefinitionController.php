<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDefinitionsRequest;
use App\Http\Resources\FieldDefinitionResource;
use App\Models\FieldDefinition;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** MOD-05 Data Definition: DEF-API-001 PUT /analyses/{id}/definitions */
class DefinitionController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function __construct(private readonly AnalysisLifecycle $lifecycle) {}

    public function update(UpdateDefinitionsRequest $request, string $analysis): JsonResponse
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('update', $model);
        $this->lifecycle->assertAtLeast($model, AnalysisStatus::PROFILED, 'Saving definitions');
        $this->lifecycle->assertNotProcessing($model);

        $columns = $model->columns()->get()->keyBy('source_name');
        $definitions = $request->validated('definitions');

        $unknown = collect($definitions)->pluck('column')->reject(fn ($c) => $columns->has($c))->values();
        if ($unknown->isNotEmpty()) {
            throw ApiException::unprocessable('UNKNOWN_COLUMN', 'Some definitions reference columns that are not in the dataset.',
                $unknown->map(fn ($c) => ['field' => 'column', 'issue' => "Unknown column: {$c}"])->all());
        }

        DB::transaction(function () use ($definitions, $columns): void {
            foreach ($definitions as $definition) {
                FieldDefinition::query()->updateOrCreate(
                    ['dataset_column_id' => $columns[$definition['column']]->id],
                    [
                        'business_name' => $definition['business_name'],
                        'description' => $definition['description'] ?? null,
                        'semantic_type' => $definition['semantic_type'],
                        'unit' => $definition['unit'] ?? null,
                        'aggregation' => $definition['aggregation'] ?? null,
                        'is_suggested' => false,
                    ],
                );
            }
        });

        $this->lifecycle->transition($model, AnalysisStatus::DEFINED, AnalysisStage::DEFINITION);

        $saved = FieldDefinition::query()->whereIn('dataset_column_id', $columns->pluck('id'))->with('column')->get()->sortBy('column.position')->values();

        return response()->json(['data' => FieldDefinitionResource::collection($saved), 'meta' => ['status' => $model->status->value]]);
    }
}
