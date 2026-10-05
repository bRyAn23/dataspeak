<?php

namespace App\Models;

use App\Enums\AnalysisStage;
use App\Enums\StageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lifecycle/stage history row (table analysis_stages). Named *Record to avoid clashing with the AnalysisStage enum.
 */
class AnalysisStageRecord extends Model
{
    protected $table = 'analysis_stages';

    protected $fillable = [
        'stage', 'status', 'attempt_no', 'progress', 'started_at', 'finished_at', 'duration_ms', 'error_code', 'result_summary',
    ];

    protected function casts(): array
    {
        return [
            'stage' => AnalysisStage::class,
            'status' => StageStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'result_summary' => 'array',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
