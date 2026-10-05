<?php

namespace Database\Factories;

use App\Enums\ColumnInferredType;
use App\Models\Analysis;
use App\Models\DatasetColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DatasetColumn> */
class DatasetColumnFactory extends Factory
{
    protected $model = DatasetColumn::class;

    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'position' => fake()->unique()->numberBetween(0, 500),
            'source_name' => fake()->unique()->lexify('col_??????'),
            'inferred_type' => ColumnInferredType::NUMERIC,
            'null_count' => 0,
            'null_rate' => 0,
            'distinct_count' => 10,
            'profile_stats' => ['min' => 1, 'max' => 10, 'mean' => 5.5],
        ];
    }
}
