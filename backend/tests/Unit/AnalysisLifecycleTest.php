<?php

namespace Tests\Unit;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Exceptions\LifecycleConflictException;
use App\Models\Analysis;
use App\Services\Lifecycle\AnalysisLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_transitions_are_allowed_in_order(): void
    {
        $lifecycle = new AnalysisLifecycle;
        $analysis = Analysis::factory()->create();
        $path = [
            [AnalysisStatus::UPLOADED, AnalysisStage::UPLOAD], [AnalysisStatus::VALIDATED, AnalysisStage::VALIDATION],
            [AnalysisStatus::PROFILED, AnalysisStage::PROFILING], [AnalysisStatus::DEFINED, AnalysisStage::DEFINITION],
            [AnalysisStatus::ANALYZING, AnalysisStage::STATISTICAL_ANALYSIS], [AnalysisStatus::ANALYZED, AnalysisStage::VISUALIZATION],
            [AnalysisStatus::REPORTING, AnalysisStage::REPORTING], [AnalysisStatus::COMPLETED, AnalysisStage::COMPLETE],
        ];
        foreach ($path as [$status, $stage]) {
            $lifecycle->transition($analysis, $status, $stage);
            $this->assertSame($status, $analysis->fresh()->status);
        }
    }

    public function test_invalid_transition_throws_409_conflict(): void
    {
        $lifecycle = new AnalysisLifecycle;
        $analysis = Analysis::factory()->create();

        $this->expectException(LifecycleConflictException::class);
        $lifecycle->transition($analysis, AnalysisStatus::ANALYZED, AnalysisStage::STATISTICAL_ANALYSIS);
    }

    public function test_every_status_has_a_transition_entry_and_processing_can_fail(): void
    {
        foreach (AnalysisStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, AnalysisLifecycle::TRANSITIONS, $status->value);
        }
        $this->assertContains('FAILED', AnalysisLifecycle::TRANSITIONS['ANALYZING']);
        $this->assertContains('FAILED', AnalysisLifecycle::TRANSITIONS['REPORTING']);
        $this->assertNotContains('COMPLETED', AnalysisLifecycle::TRANSITIONS['CREATED']);
    }

    public function test_fail_records_safe_error_and_assert_at_least_rejects_failed(): void
    {
        $lifecycle = new AnalysisLifecycle;
        $analysis = Analysis::factory()->status(AnalysisStatus::ANALYZING)->create();

        $lifecycle->fail($analysis, AnalysisStage::STATISTICAL_ANALYSIS, 'ANALYTICS_METHOD_FAILED', 'Method failed.');
        $this->assertSame(AnalysisStatus::FAILED, $analysis->status);
        $this->assertSame('ANALYTICS_METHOD_FAILED', $analysis->last_error_code);

        $this->expectException(LifecycleConflictException::class);
        $lifecycle->assertAtLeast($analysis, AnalysisStatus::DEFINED, 'Run');
    }

    public function test_stage_records_track_attempts(): void
    {
        $lifecycle = new AnalysisLifecycle;
        $analysis = Analysis::factory()->create();
        $first = $lifecycle->beginStage($analysis, AnalysisStage::VALIDATION);
        $lifecycle->failStage($first, 'X');
        $second = $lifecycle->beginStage($analysis, AnalysisStage::VALIDATION);
        $lifecycle->completeStage($second, ['row_count' => 3]);

        $this->assertSame(1, $first->attempt_no);
        $this->assertSame(2, $second->attempt_no);
        $this->assertSame('SUCCEEDED', $second->fresh()->status->value);
        $this->assertSame(['row_count' => 3], $second->fresh()->result_summary);
    }
}
