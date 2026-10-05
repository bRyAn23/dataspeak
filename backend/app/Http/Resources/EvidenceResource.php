<?php

namespace App\Http\Resources;

use App\Services\Reports\DisplayLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Evidence model (FastAPI doc Section 9) as exposed to the frontend. @mixin \App\Models\Evidence */
class EvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $display = DisplayLabels::for($this->analysis); // FEAT-I18N-002

        return [
            'evidence_id' => $this->evidence_key,
            'analysis_family' => $this->analysis_family->value,
            'metric' => $this->metric,
            'method' => $this->method,
            'value' => $this->value !== null ? (float) $this->value : null,
            'display_value' => $this->display_value,
            'source_columns' => $this->source_columns,
            'metric_column' => $this->whenLoaded('metricColumn', fn () => $this->metricColumn?->source_name),
            'dimension_column' => $this->whenLoaded('dimensionColumn', fn () => $this->dimensionColumn?->source_name),
            // Business names from the user's definitions, for readers who should never see raw column names.
            'label' => $this->whenLoaded('metricColumn', fn () => $display->label($this->metricColumn?->definition?->business_name ?? $this->metricColumn?->source_name)),
            'dimension' => $this->whenLoaded('dimensionColumn', fn () => $display->label($this->dimensionColumn?->definition?->business_name ?? $this->dimensionColumn?->source_name)),
            'parameters' => $this->parameters,
            'quality' => ['status' => $this->quality_status->value],
            'payload' => $this->payload,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
