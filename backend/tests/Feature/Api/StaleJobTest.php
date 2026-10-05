<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Enums\StageStatus;
use App\Jobs\GenerateReportJob;
use App\Models\Analysis;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\AiScenario;
use Tests\Support\ApiTestCase;

/** NFR-REL-001: a job that dies with its worker must not leave the analysis spinning, and the report can be rebuilt. */
class StaleJobTest extends ApiTestCase
{
    private function reporting(Analysis $analysis, int $minutesAgo): void
    {
        $analysis->forceFill(['status' => AnalysisStatus::REPORTING, 'current_stage' => AnalysisStage::AI_SYNTHESIS])->save();
        $analysis->stages()->create(['stage' => AnalysisStage::AI_SYNTHESIS, 'status' => StageStatus::RUNNING, 'attempt_no' => 1, 'progress' => 0, 'started_at' => now()->subMinutes($minutesAgo)]);
    }

    public function test_status_marks_a_long_dead_report_job_interrupted_but_leaves_a_live_one_alone(): void
    {
        $stale = AiScenario::build($user = $this->actingAsUser());
        $this->reporting($stale, 30);
        $live = AiScenario::build($user);
        $this->reporting($live, 1);

        $this->getJson($this->urlFor($stale, '/status'))->assertOk()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.is_terminal', true)
            ->assertJsonPath('data.last_error.code', 'JOB_INTERRUPTED');
        $this->assertSame(StageStatus::FAILED, $stale->stages()->where('stage', 'AI_SYNTHESIS')->first()->status);

        $this->getJson($this->urlFor($live, '/status'))->assertOk()->assertJsonPath('data.status', 'REPORTING');
        $this->getJson($this->urlFor($stale))->assertOk()->assertJsonPath('data.can_rebuild_report', true);
    }

    public function test_report_can_be_rebuilt_after_a_report_phase_failure_but_not_after_an_analysis_failure(): void
    {
        Queue::fake();
        $analysis = AiScenario::build($this->actingAsUser());
        $this->reporting($analysis, 30);
        $this->artisan('dataspeak:reap-stale-jobs')->expectsOutputToContain('1 analysis(es) marked interrupted')->assertSuccessful();

        $this->postJson($this->urlFor($analysis, '/report'), [], ['Idempotency-Key' => 'k1'])->assertStatus(202);
        Queue::assertPushed(GenerateReportJob::class);
        $this->assertSame(AnalysisStatus::REPORTING, $analysis->fresh()->status);
        $this->assertNull($analysis->fresh()->last_error_code);

        $other = AiScenario::build($this->actingAsUser());
        $other->forceFill(['status' => AnalysisStatus::FAILED, 'current_stage' => AnalysisStage::STATISTICAL_ANALYSIS])->save();
        $this->getJson($this->urlFor($other))->assertOk()->assertJsonPath('data.can_rebuild_report', false);
        $this->postJson($this->urlFor($other, '/report'), [], ['Idempotency-Key' => 'k2'])->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');
    }

    public function test_queue_failure_hook_marks_the_analysis_failed(): void
    {
        $analysis = AiScenario::build($this->actingAsUser());
        $this->reporting($analysis, 0);

        (new GenerateReportJob($analysis->id, 'job_x'))->failed(new RuntimeException('killed'));

        $fresh = $analysis->fresh();
        $this->assertSame(AnalysisStatus::FAILED, $fresh->status);
        $this->assertSame('JOB_INTERRUPTED', $fresh->last_error_code);
    }

    public function test_force_option_targets_one_analysis(): void
    {
        $a = AiScenario::build($user = $this->actingAsUser());
        $this->reporting($a, 0);
        $b = AiScenario::build($user);
        $this->reporting($b, 0);

        $this->artisan('dataspeak:reap-stale-jobs', ['--force' => true, '--analysis' => $a->public_id])->assertSuccessful();
        $this->assertSame(AnalysisStatus::FAILED, $a->fresh()->status);
        $this->assertSame(AnalysisStatus::REPORTING, $b->fresh()->status);
    }
}
