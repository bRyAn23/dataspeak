<?php

namespace Database\Factories;

use App\Enums\ChartType;
use App\Models\Analysis;
use App\Models\Visualization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Visualization> */
class VisualizationFactory extends Factory
{
    protected $model = Visualization::class;

    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'insight_id' => null,
            'chart_type' => ChartType::BAR,
            'title' => 'Revenue by region',
            'spec' => ['x' => ['field' => 'region', 'label' => 'Region'], 'y' => ['field' => 'revenue', 'label' => 'Revenue', 'unit' => 'THB'], 'series' => []],
            'evidence_keys' => [],
            'position' => 0,
        ];
    }
}
