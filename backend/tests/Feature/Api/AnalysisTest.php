<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\Claim;
use App\Models\Evidence;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApiTestCase;

/** ANL-API-001..004 and FEAT-SEC-001 */
class AnalysisTest extends ApiTestCase
{
    public function test_create_returns_shell_with_uuid_and_initial_state(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/analyses', ['name' => 'September Sales Analysis']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'September Sales Analysis')
            ->assertJsonPath('data.status', 'CREATED')
            ->assertJsonPath('data.current_stage', 'UPLOAD')
            ->assertJsonPath('data.dataset.transient_file_present', false);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->json('data.id'));
    }

    public function test_create_requires_a_name(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/analyses', [])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_list_is_paginated_and_scoped_to_the_user(): void
    {
        $user = $this->actingAsUser();
        Analysis::factory()->count(3)->for($user)->create();
        Analysis::factory()->count(2)->create();

        $this->getJson('/api/v1/analyses?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_show_includes_stage_history(): void
    {
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->create();
        $analysis->stages()->create(['stage' => 'UPLOAD', 'status' => 'SUCCEEDED', 'progress' => 100]);

        $this->getJson($this->urlFor($analysis))
            ->assertOk()
            ->assertJsonPath('data.id', $analysis->public_id)
            ->assertJsonPath('data.stages.0.stage', 'UPLOAD')
            ->assertJsonPath('data.has_definitions', false);
    }

    public function test_other_users_analyses_are_not_found(): void
    {
        $this->actingAsUser();
        $foreign = Analysis::factory()->create();

        $this->getJson($this->urlFor($foreign))->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->deleteJson($this->urlFor($foreign))->assertStatus(404);
        $this->postJson($this->urlFor($foreign, '/run'))->assertStatus(404);
        $this->assertDatabaseHas('analyses', ['id' => $foreign->id]);
    }

    public function test_delete_removes_derived_records_and_transient_file(): void
    {
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->withTransientFile()->status(AnalysisStatus::ANALYZED)->create();
        $this->placeTransientFile($analysis);
        $evidence = Evidence::factory()->for($analysis)->create();
        $claim = Claim::factory()->for($analysis)->create();
        $claim->evidence()->attach($evidence->id, ['is_primary' => true]);
        $analysis->stages()->create(['stage' => 'UPLOAD', 'status' => 'SUCCEEDED']);

        $this->deleteJson($this->urlFor($analysis))->assertNoContent();

        $this->assertDatabaseMissing('analyses', ['id' => $analysis->id]);
        $this->assertDatabaseCount('evidence', 0);
        $this->assertDatabaseCount('claims', 0);
        $this->assertDatabaseCount('claim_evidence', 0);
        $this->assertDatabaseCount('analysis_stages', 0);
        Storage::disk('transient')->assertMissing('analyses/'.$analysis->public_id.'/dataset.csv');
    }

    public function test_requests_without_token_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/analyses')->assertStatus(401);
    }
}
