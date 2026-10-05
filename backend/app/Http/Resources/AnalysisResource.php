<?php

namespace App\Http\Resources;

use App\Models\Analysis;
use App\Support\ChartThemes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Analysis */
class AnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'status' => $this->status->value,
            'current_stage' => $this->current_stage->value,
            'dataset' => [
                'file_name' => $this->source_file_name,
                'size_bytes' => $this->source_file_size_bytes,
                'sha256' => $this->file_sha256,
                'row_count' => $this->row_count,
                'column_count' => $this->column_count,
                'completeness_pct' => $this->completeness_pct !== null ? (float) $this->completeness_pct : null,
                'uploaded_at' => $this->csv_uploaded_at?->toIso8601ZuluString(),
                'purged_at' => $this->csv_purged_at?->toIso8601ZuluString(),
                'transient_file_present' => $this->hasTransientFile(),
            ],
            'report_language' => $this->report_language ?? 'en',
            'chart_theme' => ChartThemes::normalize($this->chart_theme), // FEAT-VIS-004
            'last_error' => $this->last_error_code ? ['code' => $this->last_error_code, 'message' => $this->last_error_message] : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
