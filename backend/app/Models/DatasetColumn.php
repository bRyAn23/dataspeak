<?php

namespace App\Models;

use App\Enums\ColumnInferredType;
use Database\Factories\DatasetColumnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Derived column metadata (MOD-04). Header names and profile statistics; not raw rows. */
class DatasetColumn extends Model
{
    /** @use HasFactory<DatasetColumnFactory> */
    use HasFactory;

    protected $fillable = ['position', 'source_name', 'inferred_type', 'null_count', 'null_rate', 'distinct_count', 'profile_stats'];

    protected function casts(): array
    {
        return [
            'inferred_type' => ColumnInferredType::class,
            'null_rate' => 'decimal:6',
            'profile_stats' => 'array',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /** @return HasOne<FieldDefinition, $this> */
    public function definition(): HasOne
    {
        return $this->hasOne(FieldDefinition::class);
    }
}
