<?php

namespace Database\Factories;

use App\Enums\AnalysisFamily;
use App\Enums\QualityStatus;
use App\Models\Analysis;
use App\Models\Evidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Evidence> */
class EvidenceFactory extends Factory
{
    protected $model = Evidence::class;

    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'evidence_key' => 'ev_'.fake()->unique()->regexify('[a-f0-9]{12}'),
            'analysis_family' => AnalysisFamily::DESCRIPTIVE,
            'metric' => 'mean',
            'method' => 'arithmetic_mean',
            'value' => 292.4,
            'display_value' => '292.4',
            'source_columns' => ['revenue_thb'],
            'parameters' => [],
            'quality_status' => QualityStatus::ACCEPTABLE,
            'payload' => null,
        ];
    }
}
