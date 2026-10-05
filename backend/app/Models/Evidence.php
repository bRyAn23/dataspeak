<?php

namespace App\Models;

use App\Enums\AnalysisFamily;
use App\Enums\QualityStatus;
use Database\Factories\EvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Deterministic metric with method metadata (MOD-06). Source of every number in a report. */
class Evidence extends Model
{
    /** @use HasFactory<EvidenceFactory> */
    use HasFactory;

    protected $table = 'evidence';

    public const UPDATED_AT = null;

    protected $fillable = [
        'evidence_key', 'analysis_family', 'metric', 'method', 'value', 'display_value',
        'metric_column_id', 'dimension_column_id', 'source_columns', 'parameters', 'quality_status', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'analysis_family' => AnalysisFamily::class,
            'quality_status' => QualityStatus::class,
            'value' => 'decimal:8',
            'source_columns' => 'array',
            'parameters' => 'array',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /** @return BelongsTo<DatasetColumn, $this> */
    public function metricColumn(): BelongsTo
    {
        return $this->belongsTo(DatasetColumn::class, 'metric_column_id');
    }

    /** @return BelongsTo<DatasetColumn, $this> */
    public function dimensionColumn(): BelongsTo
    {
        return $this->belongsTo(DatasetColumn::class, 'dimension_column_id');
    }

    /** @return BelongsToMany<Claim, $this> */
    public function claims(): BelongsToMany
    {
        return $this->belongsToMany(Claim::class, 'claim_evidence')->withPivot('is_primary');
    }
}
