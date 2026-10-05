<?php

namespace App\Services\Analytics;

use App\Models\Analysis;

/** Serialises stored field definitions into the contract's FieldDefinition objects. */
class DefinitionPayload
{
    /** @return list<array<string, mixed>> */
    public static function for(Analysis $analysis): array
    {
        return $analysis->columns()->with('definition')->get()
            ->filter(fn ($column) => $column->definition !== null)
            ->map(fn ($column) => [
                'column' => $column->source_name,
                'business_name' => $column->definition->business_name,
                'semantic_type' => $column->definition->semantic_type->value,
                'unit' => $column->definition->unit,
                'aggregation' => $column->definition->aggregation?->value,
            ])->values()->all();
    }
}
