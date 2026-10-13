<?php

namespace App\Http\Resources;

use App\Models\FieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FieldDefinition */
class FieldDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'column' => $this->column->source_name,
            'business_name' => $this->business_name,
            'description' => $this->description,
            'semantic_type' => $this->semantic_type->value,
            'unit' => $this->unit,
            'aggregation' => $this->aggregation?->value,
            'is_suggested' => $this->is_suggested,
            'suggestion_reason' => $this->suggestion_reason, // FEAT-DEF-004: why this meaning and aggregation were suggested
        ];
    }
}
