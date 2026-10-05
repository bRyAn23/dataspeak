<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Jobs\RunAnalysisJob;
use App\Models\Analysis;
use App\Models\DatasetColumn;
use App\Models\FieldDefinition;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ApiTestCase;

/** RUN-API-001/002, FEAT-OPS-001 */
class RunAndStatusTest extends ApiTestCase
{
    private function defined(AnalysisStatus $status = AnalysisStatus::DEFINED): Analysis
    {
        $analysis = Analysis::factory()->for($this->actingAsUser())->withTransientFile()->status($status)->create();
        $this->placeTransientFile($analysis);
        $column = DatasetColumn::factory()->for($analysis)->create(['source_name' => 'revenue_thb']);
        FieldDefinition::factory()->for($column, 'column')->create();

        return $analysis;
    }

    public function test_run_queues_the_job_and_returns_202(): void
    {
        Queue::fake();
        $analysis = $this->defined();

        $this->postJson($this->urlFor($analysis, '/run'))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'QUEUED')
            ->assertJsonPath('data.analysis_id', $analysis->public_id);

        Queue::assertPushed(RunAnalysisJob::class, fn (RunAnalysisJob $job) => $job->analysisId === $analysis->id);
        $this->assertSame(AnalysisStatus::ANALYZING, $analysis->fresh()->status);

        $this->getJson($this->urlFor($analysis, '/status'))
            ->assertOk()
            ->assertJsonPath('data.status', 'ANALYZING')
            ->assertJsonPath('data.stage', 'STATISTICAL_ANALYSIS')
            ->assertJsonPath('data.job.status', 'QUEUED')
            ->assertJsonPath('data.poll_seconds', 2)
            ->assertJsonPath('data.is_terminal', false);
    }

    public function test_run_preconditions(): void
    {
        Queue::fake();
        $busy = $this->defined(AnalysisStatus::ANALYZING);
        $this->postJson($this->urlFor($busy, '/run'))->assertStatus(409)->assertJsonPath('error.code', 'ANALYSIS_BUSY');

        $noDefinitions = Analysis::factory()->for($busy->user)->withTransientFile()->status(AnalysisStatus::PROFILED)->create();
        $this->placeTransientFile($noDefinitions);
        $this->postJson($this->urlFor($noDefinitions, '/run'))->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');

        $purged = $this->defined();
        $purged->forceFill(['csv_purged_at' => now()])->save();
        $this->postJson($this->urlFor($purged, '/run'))->assertStatus(409)->assertJsonPath('error.code', 'UPLOAD_REQUIRED');
        Queue::assertNothingPushed();
    }

    public function test_run_is_idempotent_with_idempotency_key(): void
    {
        Queue::fake();
        $analysis = $this->defined();

        $first = $this->withHeader('Idempotency-Key', 'abc-123')->postJson($this->urlFor($analysis, '/run'))->assertStatus(202);
        $second = $this->withHeader('Idempotency-Key', 'abc-123')->postJson($this->urlFor($analysis, '/run'))->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.job_id'), $second->json('data.job_id'));
        Queue::assertPushed(RunAnalysisJob::class, 1);
    }

    public function test_retry_from_failed_is_allowed_while_file_exists(): void
    {
        Queue::fake();
        $analysis = $this->defined(AnalysisStatus::FAILED);
        $this->postJson($this->urlFor($analysis, '/run'))->assertStatus(202);
        $this->assertSame(AnalysisStatus::ANALYZING, $analysis->fresh()->status);
    }

    public function test_status_reports_terminal_states(): void
    {
        $analysis = Analysis::factory()->for($this->actingAsUser())->status(AnalysisStatus::FAILED)->create(['last_error_code' => 'ANALYTICS_INSUFFICIENT_DATA', 'last_error_message' => 'Not enough rows.']);
        $this->getJson($this->urlFor($analysis, '/status'))
            ->assertOk()->assertJsonPath('data.is_terminal', true)->assertJsonPath('data.last_error.code', 'ANALYTICS_INSUFFICIENT_DATA');
    }
}
