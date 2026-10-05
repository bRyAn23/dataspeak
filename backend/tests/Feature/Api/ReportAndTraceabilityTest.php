<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Jobs\GenerateReportJob;
use App\Models\Analysis;
use App\Models\Claim;
use App\Models\Evidence;
use App\Models\Insight;
use App\Models\Visualization;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ApiTestCase;

/** INS-API-001, VIS-API-001, RPT-API-001/002, TRC-API-001 */
class ReportAndTraceabilityTest extends ApiTestCase
{
    private function analyzed(): Analysis
    {
        $analysis = Analysis::factory()->for($this->actingAsUser())->status(AnalysisStatus::ANALYZED)->create(['name' => 'Retail 2025']);
        $evidence = Evidence::factory()->for($analysis)->create(['metric' => 'mean', 'display_value' => '292.4']);
        Insight::factory()->for($analysis)->for($evidence)->create(['rank' => 1]);
        Visualization::factory()->for($analysis)->create();
        Evidence::factory()->for($analysis)->create(['analysis_family' => 'QUALITY', 'quality_status' => 'WARNING', 'metric' => 'null_rate', 'display_value' => '12.7%']);

        return $analysis;
    }

    public function test_insights_and_visualizations_require_analyzed_state(): void
    {
        $analysis = Analysis::factory()->for($this->actingAsUser())->status(AnalysisStatus::DEFINED)->create();
        $this->getJson($this->urlFor($analysis, '/insights'))->assertStatus(409);
        $this->getJson($this->urlFor($analysis, '/visualizations'))->assertStatus(409);
    }

    public function test_insights_and_visualizations_are_listed_with_evidence_links(): void
    {
        $analysis = $this->analyzed();

        $this->getJson($this->urlFor($analysis, '/insights'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.0.evidence.metric', 'mean');

        $this->getJson($this->urlFor($analysis, '/visualizations'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'BAR')
            ->assertJsonPath('data.0.y.unit', 'THB');
    }

    public function test_report_generation_is_asynchronous_and_assembles_deterministic_content(): void
    {
        Http::fake(fn () => throw new ConnectionException('ollama down'));
        config(['services.ollama.base_url' => 'http://ollama.test']);
        Queue::fake();
        $analysis = $this->analyzed();

        $this->postJson($this->urlFor($analysis, '/report'))->assertStatus(202)->assertJsonPath('data.status', 'QUEUED');
        $this->assertSame(AnalysisStatus::REPORTING, $analysis->fresh()->status);
        Queue::assertPushed(GenerateReportJob::class);

        app()->call([new GenerateReportJob($analysis->id, 'job_test'), 'handle']);

        $this->assertSame(AnalysisStatus::COMPLETED, $analysis->fresh()->status);
        $this->getJson($this->urlFor($analysis, '/report'))
            ->assertOk()
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.title', 'Retail 2025')
            ->assertJsonPath('data.ai.status', 'unavailable')
            ->assertJsonPath('data.summary_metrics.0.display_value', '292.4')
            ->assertJsonPath('data.data_notes.0.severity', 'WARNING')
            ->assertJsonCount(1, 'data.insights')
            ->assertJsonCount(0, 'data.claims');
    }

    public function test_report_read_before_generation_and_generation_before_analysis_conflict(): void
    {
        $analysis = Analysis::factory()->for($this->actingAsUser())->status(AnalysisStatus::DEFINED)->create();
        $this->getJson($this->urlFor($analysis, '/report'))->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');
        $this->postJson($this->urlFor($analysis, '/report'))->assertStatus(409);
    }

    public function test_claim_resolves_to_its_evidence(): void
    {
        $analysis = $this->analyzed();
        $claim = Claim::factory()->for($analysis)->create();
        $claim->evidence()->attach($analysis->evidence()->first()->id, ['is_primary' => true]);

        $this->getJson($this->urlFor($analysis, '/claims/'.$claim->public_id))
            ->assertOk()
            ->assertJsonPath('data.id', $claim->public_id)
            ->assertJsonPath('data.status', 'SUPPORTED')
            ->assertJsonPath('data.evidence.0.method', 'arithmetic_mean')
            ->assertJsonPath('data.evidence.0.source_columns.0', 'revenue_thb')
            ->assertJsonPath('data.primary_evidence_id', $analysis->evidence()->first()->evidence_key);
    }

    public function test_claim_from_another_analysis_is_not_found(): void
    {
        $analysis = $this->analyzed();
        $foreignClaim = Claim::factory()->create();
        $this->getJson($this->urlFor($analysis, '/claims/'.$foreignClaim->public_id))->assertStatus(404);
    }
}
