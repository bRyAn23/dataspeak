<?php

namespace App\Models;

use App\Enums\ChartType;
use App\Enums\VisualizationSource;
use Database\Factories\VisualizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Chart specification (MOD-08). The frontend renders; nothing is computed here. */
class Visualization extends Model
{
    /** @use HasFactory<VisualizationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['insight_id', 'chart_type', 'source', 'title', 'spec', 'evidence_keys', 'position', 'is_selected'];

    protected function casts(): array
    {
        return [
            'chart_type' => ChartType::class,
            'source' => VisualizationSource::class,
            'is_selected' => 'boolean',
            'spec' => 'array',
            'evidence_keys' => 'array',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /** @return BelongsTo<Insight, $this> */
    public function insight(): BelongsTo
    {
        return $this->belongsTo(Insight::class);
    }
}
