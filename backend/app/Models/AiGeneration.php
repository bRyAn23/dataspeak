<?php

namespace App\Models;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** AI interpretation request metadata (MOD-09, planned). Requests carry evidence, never rows. */
class AiGeneration extends Model
{
    protected $fillable = [
        'generation_type', 'status', 'attempt_no', 'model', 'prompt_version', 'request_payload', 'response_payload',
        'input_tokens', 'output_tokens', 'duration_ms', 'error_code',
    ];

    protected function casts(): array
    {
        return [
            'generation_type' => AiGenerationType::class,
            'status' => AiGenerationStatus::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /** @return HasMany<Claim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }
}
