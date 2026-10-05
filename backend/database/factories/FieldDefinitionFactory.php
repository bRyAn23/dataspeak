<?php

namespace Database\Factories;

use App\Enums\Aggregation;
use App\Enums\SemanticType;
use App\Models\DatasetColumn;
use App\Models\FieldDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FieldDefinition> */
class FieldDefinitionFactory extends Factory
{
    protected $model = FieldDefinition::class;

    public function definition(): array
    {
        return [
            'dataset_column_id' => DatasetColumn::factory(),
            'business_name' => fake()->words(2, true),
            'semantic_type' => SemanticType::NUMBER,
            'unit' => null,
            'aggregation' => Aggregation::SUM,
            'is_suggested' => false,
        ];
    }
}
