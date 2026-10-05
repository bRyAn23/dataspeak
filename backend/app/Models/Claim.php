<?php

namespace App\Models;

use App\Enums\ClaimKind;
use App\Enums\ClaimStatus;
use Database\Factories\ClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/** Structured claim with validation state (MOD-10/11). Evidence links via claim_evidence. */
class Claim extends Model
{
    /** @use HasFactory<ClaimFactory> */
    use HasFactory;

    protected $fillable = [
        'ai_generation_id', 'claim_kind', 'statement', 'claimed_value', 'computed_value', 'status',
        'validation_method', 'validation_notes', 'in_report', 'position',
    ];

    protected function casts(): array
    {
        return [
            'statement_translations' => 'array',
            'claim_kind' => ClaimKind::class,
            'status' => ClaimStatus::class,
            'in_report' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $claim): void {
            $claim->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /** @return BelongsTo<AiGeneration, $this> */
    public function aiGeneration(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class);
    }

    /** @return BelongsToMany<Evidence, $this> */
    public function evidence(): BelongsToMany
    {
        return $this->belongsToMany(Evidence::class, 'claim_evidence')->withPivot('is_primary');
    }
}
