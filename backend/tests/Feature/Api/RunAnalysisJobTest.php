<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Jobs\GenerateReportJob;
use App\Jobs\RunAnalysisJob;
use App\Models\Analysis;
use App\Models\DatasetColumn;
use App\Models\FieldDefinition;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApiTestCase;

/** RUN-API-001 pipeline, FEAT-ANA/INS/VIS persistence, FEAT-OPS-002 failure handling, FEAT-UPL-003 cleanup */
class RunAnalysisJobTest extends ApiTestCase
{
    private function analyzing(): Analysis
    {
        $analysis = Analysis::factory()->withTransientFile()->status(AnalysisStatus::ANALYZING)->create();
        $this->placeTransientFile($analysis);
        $column = DatasetColumn::factory()->for($analysis)->create(['source_name' => 'revenue_thb']);
        FieldDefinition::factory()->for($column, 'column')->create(['semantic_type' => 'CURRENCY', 'unit' => 'THB']);

        return $analysis;
    }

    private function runJob(Analysis $analysis): void
    {
        app()->call([new RunAnalysisJob($analysis->id, 'job_test'), 'handle']);
    }

    public function test_successful_pipeline_persists_results_and_deletes_the_transient_file(): void
    {
        $evidence = [$this->sampleEvidence('ev_111111111111'), array_replace($this->sampleEvidence('ev_222222222222'), ['analysis_family' => 'quality', 'quality' => ['status' => 'warning']])];
        Http::fake([
            'analytics.test/v1/analyze' => Http::response(['analysis_id' => 'x', 'evidence' => $evidence, 'summary' => ['evidence_count' => 2, 'families_run' => ['descriptive']]]),
            'analytics.test/v1/insights' => Http::response(['insights' => [
                ['insight_type' => 'distribution', 'headline' => 'Revenue is concentrated', 'description' => 'd', 'score' => 0.7, 'rank' => 1, 'primary_evidence_id' => 'ev_111111111111', 'evidence_ids' => ['ev_111111111111']],
                ['insight_type' => 'distribution', 'headline' => 'Orphan', 'score' => 0.5, 'rank' => 2, 'primary_evidence_id' => 'ev_missing', 'evidence_ids' => ['ev_missing']],
            ]]),
            'analytics.test/v1/visualizations/recommend' => Http::response(['visualizations' => [
                ['type' => 'bar', 'title' => 'Revenue by region', 'x' => ['field' => 'region', 'label' => 'Region'], 'y' => ['field' => 'revenue', 'label' => 'Revenue', 'unit' => 'THB'], 'unit_missing' => false, 'series' => [['region' => 'North', 'revenue' => 10]], 'evidence_ids' => ['ev_111111111111'], 'insight_rank' => 1],
            ]]),
        ]);
        $analysis = $this->analyzing();
        Queue::fake();

        $this->runJob($analysis);

        $analysis->refresh();
        // Deterministic results are persisted, then the report job is chained automatically (one-click flow).
        $this->assertSame(AnalysisStatus::REPORTING, $analysis->status);
        Queue::assertPushed(GenerateReportJob::class, fn ($job) => $job->analysisId === $analysis->id);
        $this->assertDatabaseHas('analysis_stages', ['analysis_id' => $analysis->id, 'stage' => 'VISUALIZATION', 'status' => 'SUCCEEDED']);
        $this->assertDatabaseCount('evidence', 2);
        $this->assertDatabaseHas('evidence', ['evidence_key' => 'ev_222222222222', 'analysis_family' => 'QUALITY', 'quality_status' => 'WARNING']);
        $this->assertDatabaseCount('insights', 1);
        $this->assertDatabaseHas('visualizations', ['chart_type' => 'BAR', 'title' => 'Revenue by region']);
        Storage::disk('transient')->assertMissing('analyses/'.$analysis->public_id.'/dataset.csv');
        $this->assertNotNull($analysis->csv_purged_at);
        $this->assertSame(1, $analysis->evidence()->where('evidence_key', 'ev_111111111111')->first()->metricColumn()->count());

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/analyze') && str_contains((string) $r->body(), '"semantic_type":"CURRENCY"'));
    }

    public function test_auto_report_can_be_disabled(): void
    {
        config(['dataspeak.auto_report' => false]);
        Http::fake([
            'analytics.test/v1/analyze' => Http::response(['analysis_id' => 'x', 'evidence' => [$this->sampleEvidence('ev_111111111111')], 'summary' => ['evidence_count' => 1, 'families_run' => ['descriptive']]]),
            'analytics.test/v1/insights' => Http::response(['insights' => []]),
            'analytics.test/v1/visualizations/recommend' => Http::response(['visualizations' => []]),
        ]);
        $analysis = $this->analyzing();
        Queue::fake();

        $this->runJob($analysis);

        $this->assertSame(AnalysisStatus::ANALYZED, $analysis->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_dataset_failure_marks_failed_and_deletes_file(): void
    {
        Http::fake(['analytics.test/v1/analyze' => Http::response(['error' => ['code' => 'ANALYTICS_INSUFFICIENT_DATA', 'message' => 'Not enough valid observations.']], 422)]);
        $analysis = $this->analyzing();

        $this->runJob($analysis);

        $analysis->refresh();
        $this->assertSame(AnalysisStatus::FAILED, $analysis->status);
        $this->assertSame('ANALYTICS_INSUFFICIENT_DATA', $analysis->last_error_code);
        $this->assertDatabaseHas('analysis_stages', ['analysis_id' => $analysis->id, 'stage' => 'STATISTICAL_ANALYSIS', 'status' => 'FAILED', 'error_code' => 'ANALYTICS_INSUFFICIENT_DATA']);
        Storage::disk('transient')->assertMissing('analyses/'.$analysis->public_id.'/dataset.csv');
    }

    public function test_transient_failure_marks_failed_but_keeps_file_for_retry(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $analysis = $this->analyzing();

        $this->runJob($analysis);

        $analysis->refresh();
        $this->assertSame(AnalysisStatus::FAILED, $analysis->status);
        $this->assertSame('ANALYTICS_UNAVAILABLE', $analysis->last_error_code);
        Storage::disk('transient')->assertExists('analyses/'.$analysis->public_id.'/dataset.csv');
        $this->assertTrue($analysis->hasTransientFile());
    }

    public function test_job_is_a_no_op_when_analysis_is_not_analyzing(): void
    {
        Http::fake();
        $analysis = Analysis::factory()->status(AnalysisStatus::DEFINED)->create();
        $this->runJob($analysis);
        Http::assertNothingSent();
        $this->assertSame(AnalysisStatus::DEFINED, $analysis->fresh()->status);
    }
}
