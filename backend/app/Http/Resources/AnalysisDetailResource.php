<?php

namespace App\Http\Resources;

use App\Enums\AnalysisStatus;
use App\Services\Lifecycle\AnalysisLifecycle;
use Illuminate\Http\Request;

/** ANL-API-003: analysis plus stage history and job progress. @mixin \App\Models\Analysis */
class AnalysisDetailResource extends AnalysisResource
{
    public function __construct($resource, private readonly ?array $job = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'job' => $this->job,
            'stages' => $this->stages->sortBy('created_at')->values()->map(fn ($s) => [
                'stage' => $s->stage->value,
                'status' => $s->status->value,
                'attempt_no' => $s->attempt_no,
                'progress' => $s->progress,
                'started_at' => $s->started_at?->toIso8601ZuluString(),
                'finished_at' => $s->finished_at?->toIso8601ZuluString(),
                'duration_ms' => $s->duration_ms,
                'error_code' => $s->error_code,
                'result_summary' => $s->result_summary,
            ])->all(),
            'has_definitions' => $this->columns()->whereHas('definition')->exists(),
            'has_report' => $this->report()->exists(),
            'can_rebuild_report' => app(AnalysisLifecycle::class)->canBuildReport($this->resource) && $this->status === AnalysisStatus::FAILED,
        ];
    }
}
