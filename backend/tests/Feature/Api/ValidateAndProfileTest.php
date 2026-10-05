<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Jobs\ProfileAnalysisJob;
use App\Models\Analysis;
use App\Services\Ai\Contracts\LlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApiTestCase;
use Tests\Support\FakeLlmClient;

/** DATA-API-002, PROF-API-001/002, FEAT-UPL-002, FEAT-PRO-001 */
class ValidateAndProfileTest extends ApiTestCase
{
    public function test_validate_success_moves_to_validated_and_sends_internal_token(): void
    {
        Http::fake(['analytics.test/v1/validate' => Http::response(['valid' => true, 'row_count' => 2, 'column_count' => 5, 'columns' => ['a'], 'warnings' => [['code' => 'X', 'message' => 'w', 'column' => null]], 'errors' => []])]);
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->withTransientFile()->status(AnalysisStatus::UPLOADED)->create();
        $this->placeTransientFile($analysis);

        $this->postJson($this->urlFor($analysis, '/validate'))
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonCount(1, 'data.warnings');

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Internal-Token', 'test-token') && $r->hasFile('file') && str_contains($r->url(), '/v1/validate'));
        $this->assertSame(AnalysisStatus::VALIDATED, $analysis->fresh()->status);
    }

    public function test_validate_failure_returns_422_with_details_and_keeps_uploaded(): void
    {
        Http::fake(['analytics.test/v1/validate' => Http::response(['valid' => false, 'row_count' => 0, 'column_count' => 0, 'columns' => [], 'warnings' => [], 'errors' => [['code' => 'MISSING_HEADER', 'message' => 'Header row is missing.', 'column' => null]]])]);
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->withTransientFile()->status(AnalysisStatus::UPLOADED)->create();
        $this->placeTransientFile($analysis);

        $this->postJson($this->urlFor($analysis, '/validate'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CSV_VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.issue', 'Header row is missing.');
        $this->assertSame(AnalysisStatus::UPLOADED, $analysis->fresh()->status);
        $this->assertDatabaseHas('analysis_stages', ['analysis_id' => $analysis->id, 'stage' => 'VALIDATION', 'status' => 'FAILED']);
    }

    public function test_validate_when_analytics_is_down_returns_503(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->withTransientFile()->status(AnalysisStatus::UPLOADED)->create();
        $this->placeTransientFile($analysis);

        $this->postJson($this->urlFor($analysis, '/validate'))->assertStatus(503)->assertJsonPath('error.code', 'ANALYTICS_UNAVAILABLE');
    }

    public function test_validate_preconditions(): void
    {
        $user = $this->actingAsUser();
        $created = Analysis::factory()->for($user)->create();
        $this->postJson($this->urlFor($created, '/validate'))->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');

        $purged = Analysis::factory()->for($user)->withTransientFile()->status(AnalysisStatus::UPLOADED)->create(['csv_purged_at' => now()]);
        $this->postJson($this->urlFor($purged, '/validate'))->assertStatus(409)->assertJsonPath('error.code', 'UPLOAD_REQUIRED');
    }

    public function test_profile_persists_derived_columns_with_unicode_headers(): void
    {
        Http::fake(['analytics.test/v1/profile' => Http::response([
            'dataset' => ['rows' => 2, 'columns' => 2, 'completeness' => 0.981, 'duplicate_rows' => 0],
            'columns' => [
                ['name' => 'ยอดขาย', 'position' => 0, 'inferred_type' => 'numeric', 'null_count' => 0, 'null_rate' => 0, 'distinct_count' => 2, 'stats' => ['min' => 10, 'max' => 11]],
                ['name' => 'ရောင်းအား', 'position' => 1, 'inferred_type' => 'category', 'null_count' => 1, 'null_rate' => 0.5, 'distinct_count' => 1, 'stats' => ['top_share' => 1.0]],
            ],
            'quality_findings' => [],
        ])]);
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->withTransientFile()->status(AnalysisStatus::VALIDATED)->create();
        $this->placeTransientFile($analysis);

        $this->app->instance(LlmClient::class, $llm = new FakeLlmClient(['columns' => [
            ['column' => 'ยอดขาย', 'business_name' => 'Sales', 'semantic_type' => 'CURRENCY', 'unit' => 'THB', 'aggregation' => 'SUM', 'description' => 'Sales amount per order.'],
            ['column' => 'ရောင်းအား', 'business_name' => 'Sales Volume', 'semantic_type' => 'CURRENCY', 'unit' => 'THB', 'aggregation' => 'SUM', 'description' => 'x'], // category values: money rejected
        ]]));

        // PROF-API-001 is asynchronous (202); the sync queue driver in tests runs the job inline.
        $this->postJson($this->urlFor($analysis, '/profile'))->assertStatus(202)->assertJsonPath('data.status', 'QUEUED')->assertJsonStructure(['data' => ['job_id']]);
        $this->getJson($this->urlFor($analysis, '/status'))->assertOk()
            ->assertJsonPath('data.status', 'PROFILED')->assertJsonPath('data.job.status', 'SUCCEEDED')->assertJsonPath('data.job.kind', 'profile')->assertJsonPath('data.progress', 100);

        $this->getJson($this->urlFor($analysis, '/profile'))
            ->assertOk()
            ->assertJsonPath('data.status', 'PROFILED')
            ->assertJsonPath('data.dataset.completeness_pct', 98.1)
            ->assertJsonPath('data.columns.0.name', 'ยอดขาย')
            ->assertJsonPath('data.columns.1.inferred_type', 'CATEGORY')
            // FEAT-DEF-003: suggested definitions are there straight after profiling
            ->assertJsonPath('data.columns.0.definition.business_name', 'Sales')
            ->assertJsonPath('data.columns.0.definition.semantic_type', 'CURRENCY')
            ->assertJsonPath('data.columns.0.definition.unit', 'THB')
            ->assertJsonPath('data.columns.0.definition.is_suggested', true)
            ->assertJsonPath('data.columns.1.definition.business_name', 'Sales Volume')
            ->assertJsonPath('data.columns.1.definition.semantic_type', 'CATEGORY')
            ->assertJsonPath('data.suggestions.ai_status', 'ready')
            ->assertJsonPath('data.suggestions.model', 'qwen3:8b');
        $this->assertSame(1, $llm->calls);

        $this->getJson($this->urlFor($analysis, '/profile'))->assertOk()->assertJsonCount(2, 'data.columns')->assertJsonPath('data.suggestions.ai_count', 2);
        $this->assertDatabaseHas('dataset_columns', ['analysis_id' => $analysis->id, 'source_name' => 'ရောင်းအား', 'inferred_type' => 'CATEGORY']);
        $this->assertDatabaseHas('field_definitions', ['business_name' => 'Sales', 'is_suggested' => true]);
    }

    public function test_reprofiling_keeps_confirmed_definitions_and_works_without_the_model(): void
    {
        Http::fake(['analytics.test/v1/profile' => Http::response([
            'dataset' => ['rows' => 2, 'columns' => 2, 'completeness' => 1.0, 'duplicate_rows' => 0],
            'columns' => [
                ['name' => 'revenue_thb', 'position' => 0, 'inferred_type' => 'numeric', 'null_count' => 0, 'null_rate' => 0, 'distinct_count' => 2, 'stats' => []],
                ['name' => 'region', 'position' => 1, 'inferred_type' => 'category', 'null_count' => 0, 'null_rate' => 0, 'distinct_count' => 2, 'stats' => []],
            ],
            'quality_findings' => [],
        ])]);
        $this->app->instance(LlmClient::class, new FakeLlmClient(null, down: true));
        $analysis = Analysis::factory()->for($this->actingAsUser())->withTransientFile()->status(AnalysisStatus::VALIDATED)->create();
        $this->placeTransientFile($analysis);
        $column = $analysis->columns()->create(['source_name' => 'region', 'position' => 1, 'inferred_type' => 'CATEGORY', 'null_count' => 0, 'null_rate' => 0]);
        $column->definition()->create(['business_name' => 'Sales Region', 'semantic_type' => 'CATEGORY', 'is_suggested' => false]);

        $this->postJson($this->urlFor($analysis, '/profile'))->assertStatus(202);
        $this->getJson($this->urlFor($analysis, '/profile'))->assertOk()
            ->assertJsonPath('data.suggestions.ai_status', 'unavailable')
            ->assertJsonPath('data.columns.0.definition.business_name', 'Revenue Thb')
            ->assertJsonPath('data.columns.0.definition.semantic_type', 'CURRENCY') // header hint, software only
            ->assertJsonPath('data.columns.1.definition.business_name', 'Sales Region')
            ->assertJsonPath('data.columns.1.definition.is_suggested', false);
    }

    public function test_profile_failure_keeps_validated_and_reports_the_error_through_status(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        $analysis = Analysis::factory()->for($this->actingAsUser())->withTransientFile()->status(AnalysisStatus::VALIDATED)->create();
        $this->placeTransientFile($analysis);

        $this->postJson($this->urlFor($analysis, '/profile'))->assertStatus(202);

        $this->getJson($this->urlFor($analysis, '/status'))->assertOk()
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.job.status', 'FAILED')
            ->assertJsonPath('data.job.stage', 'PROFILING')
            ->assertJsonPath('data.job.error.code', 'ANALYTICS_UNAVAILABLE');
        $this->getJson($this->urlFor($analysis, '/profile'))->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');
        $this->assertDatabaseHas('analysis_stages', ['analysis_id' => $analysis->id, 'stage' => 'PROFILING', 'status' => 'FAILED', 'error_code' => 'ANALYTICS_UNAVAILABLE']);
        Storage::disk('transient')->assertExists('analyses/'.$analysis->public_id.'/dataset.csv'); // kept for the retry
    }

    public function test_profile_request_is_idempotent_while_the_job_is_queued(): void
    {
        Queue::fake();
        $analysis = Analysis::factory()->for($this->actingAsUser())->withTransientFile()->status(AnalysisStatus::VALIDATED)->create();
        $this->placeTransientFile($analysis);

        $first = $this->postJson($this->urlFor($analysis, '/profile'))->assertStatus(202)->json('data.job_id');
        $second = $this->postJson($this->urlFor($analysis, '/profile'))->assertStatus(202)->json('data.job_id');

        $this->assertSame($first, $second);
        Queue::assertPushed(ProfileAnalysisJob::class, 1);
        $this->getJson($this->urlFor($analysis, '/status'))->assertOk()->assertJsonPath('data.job.status', 'QUEUED')->assertJsonPath('data.status', 'VALIDATED');
    }

    public function test_profile_read_before_profiling_is_a_conflict(): void
    {
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->status(AnalysisStatus::VALIDATED)->create();
        $this->getJson($this->urlFor($analysis, '/profile'))->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');
    }
}
