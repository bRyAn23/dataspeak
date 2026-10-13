<?php

namespace App\Models;

use App\Enums\Aggregation;
use App\Enums\SemanticType;
use Database\Factories\FieldDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Business meaning, semantic type and unit for one column (MOD-05). */
class FieldDefinition extends Model
{
    /** @use HasFactory<FieldDefinitionFactory> */
    use HasFactory;

    protected $fillable = ['dataset_column_id', 'business_name', 'description', 'semantic_type', 'unit', 'aggregation', 'is_suggested', 'suggestion_reason'];

    protected function casts(): array
    {
        return [
            'semantic_type' => SemanticType::class,
            'aggregation' => Aggregation::class,
            'is_suggested' => 'boolean',
        ];
    }

    /** @return BelongsTo<DatasetColumn, $this> */
    public function column(): BelongsTo
    {
        return $this->belongsTo(DatasetColumn::class, 'dataset_column_id');
    }
}
