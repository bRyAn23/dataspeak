<?php

namespace App\Models;

use App\Enums\InsightType;
use Database\Factories\InsightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ranked finding linked to evidence (MOD-07). */
class Insight extends Model
{
    /** @use HasFactory<InsightFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['evidence_id', 'insight_type', 'headline', 'description', 'score', 'rank', 'is_selected', 'supporting_evidence_keys'];

    protected function casts(): array
    {
        return [
            'insight_type' => InsightType::class,
            'score' => 'decimal:4',
            'is_selected' => 'boolean',
            'supporting_evidence_keys' => 'array',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /** @return BelongsTo<Evidence, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    /** @return HasMany<Visualization, $this> */
    public function visualizations(): HasMany
    {
        return $this->hasMany(Visualization::class);
    }
}
