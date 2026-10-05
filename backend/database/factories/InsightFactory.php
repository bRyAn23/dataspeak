<?php

namespace Database\Factories;

use App\Enums\InsightType;
use App\Models\Analysis;
use App\Models\Evidence;
use App\Models\Insight;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Insight> */
class InsightFactory extends Factory
{
    protected $model = Insight::class;

    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'evidence_id' => Evidence::factory(),
            'insight_type' => InsightType::GROUP_DIFFERENCE,
            'headline' => 'Revenue differs between regions',
            'description' => 'Group means differ.',
            'score' => 0.8,
            'rank' => fake()->unique()->numberBetween(1, 200),
            'is_selected' => true,
            'supporting_evidence_keys' => [],
        ];
    }
}
