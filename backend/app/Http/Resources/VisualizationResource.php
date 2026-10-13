<?php

namespace App\Http\Resources;

use App\Services\Reports\ChartReasons;
use App\Services\Reports\ChartTitles;
use App\Services\Reports\DisplayLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** VIS-API-001 item: a chart specification, never computed values. @mixin \App\Models\Visualization */
class VisualizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();
        $display = DisplayLabels::for($this->analysis, $locale); // FEAT-I18N-002/003: labels in the requested language
        $axis = function ($axis) use ($display) {
            if (is_array($axis) && isset($axis['label'])) {
                $axis['label'] = $display->text((string) $axis['label']);
            }

            return $axis;
        };

        return [
            'id' => (string) $this->id,
            'type' => $this->chart_type->value,
            'source' => $this->source?->value ?? 'RECOMMENDED',
            'is_selected' => $this->is_selected ?? true,
            'title' => $display->text(ChartTitles::localize($this->title, $locale)),
            'x' => $axis($this->spec['x'] ?? null),
            'y' => $axis($this->spec['y'] ?? null),
            'unit_missing' => (bool) ($this->spec['unit_missing'] ?? false),
            'series' => array_map(fn ($row) => is_array($row) ? $display->row($row) : $row, (array) ($this->spec['series'] ?? [])),
            'evidence_ids' => $this->evidence_keys,
            'insight_rank' => $this->insight?->rank,
            'position' => $this->position,
            'reason' => ChartReasons::for(['type' => $this->chart_type->value, 'x' => $axis($this->spec['x'] ?? null), 'y' => $axis($this->spec['y'] ?? null)], $locale), // FEAT-VIS-006
        ];
    }
}
