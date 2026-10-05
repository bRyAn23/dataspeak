<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\Analysis;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'status' => ReportStatus::READY,
            'title' => 'Executive report',
            'generated_at' => now(),
            'claims_total' => 0,
            'claims_supported' => 0,
            'summary_metrics' => [],
            'sections' => [],
            'data_notes' => [],
        ];
    }
}
