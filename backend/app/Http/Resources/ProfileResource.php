<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** PROF-API-002: derived profile only. @mixin \App\Models\Analysis */
class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'analysis_id' => $this->public_id,
            'status' => $this->status->value,
            'dataset' => [
                'rows' => $this->row_count,
                'columns' => $this->column_count,
                'completeness_pct' => $this->completeness_pct !== null ? (float) $this->completeness_pct : null,
            ],
            'columns' => $this->columns->map(fn ($c) => [
                'name' => $c->source_name,
                'position' => $c->position,
                'inferred_type' => $c->inferred_type->value,
                'null_count' => $c->null_count,
                'null_rate' => (float) $c->null_rate,
                'distinct_count' => $c->distinct_count,
                'stats' => $c->profile_stats ?? [],
                'definition' => $c->definition ? [
                    'business_name' => $c->definition->business_name,
                    'description' => $c->definition->description,
                    'semantic_type' => $c->definition->semantic_type->value,
                    'unit' => $c->definition->unit,
                    'aggregation' => $c->definition->aggregation?->value,
                    'is_suggested' => $c->definition->is_suggested,
                    'suggestion_reason' => $c->definition->suggestion_reason,
                ] : null,
            ])->values()->all(),
            'suggestions' => $this->suggestionsMeta(),
        ];
    }

    /** How the is_suggested definitions were produced at profiling time (FEAT-DEF-003). */
    private function suggestionsMeta(): ?array
    {
        $stage = $this->stages()->where('stage', 'PROFILING')->where('status', 'SUCCEEDED')->orderByDesc('finished_at')->first();
        $meta = $stage?->result_summary['suggestions'] ?? null;

        return is_array($meta) ? ['ai_status' => $meta['ai_status'] ?? null, 'model' => $meta['model'] ?? null, 'ai_count' => $meta['ai_count'] ?? 0] : null;
    }
}
