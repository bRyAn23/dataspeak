<?php

namespace Database\Factories;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Analysis> */
class AnalysisFactory extends Factory
{
    protected $model = Analysis::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'status' => AnalysisStatus::CREATED,
            'current_stage' => AnalysisStage::UPLOAD,
        ];
    }

    public function status(AnalysisStatus $status, ?AnalysisStage $stage = null): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'current_stage' => $stage ?? match ($status) {
                AnalysisStatus::CREATED, AnalysisStatus::UPLOADED => AnalysisStage::UPLOAD,
                AnalysisStatus::VALIDATED => AnalysisStage::VALIDATION,
                AnalysisStatus::PROFILED => AnalysisStage::PROFILING,
                AnalysisStatus::DEFINED => AnalysisStage::DEFINITION,
                AnalysisStatus::ANALYZING, AnalysisStatus::ANALYZED => AnalysisStage::STATISTICAL_ANALYSIS,
                AnalysisStatus::REPORTING => AnalysisStage::REPORTING,
                AnalysisStatus::COMPLETED => AnalysisStage::COMPLETE,
                AnalysisStatus::FAILED => AnalysisStage::STATISTICAL_ANALYSIS,
            },
        ]);
    }

    public function withTransientFile(): static
    {
        return $this->state(fn () => [
            'source_file_name' => 'sales.csv',
            'source_file_size_bytes' => 1024,
            'file_sha256' => str_repeat('a', 64),
            'csv_uploaded_at' => now(),
            'csv_purged_at' => null,
        ]);
    }
}
