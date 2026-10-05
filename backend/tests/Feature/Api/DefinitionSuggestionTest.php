<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\DatasetColumn;
use App\Services\Ai\Contracts\LlmClient;
use Tests\Support\ApiTestCase;
use Tests\Support\FakeLlmClient;

/** DEF-API-002 / FEAT-DEF-003 */
class DefinitionSuggestionTest extends ApiTestCase
{
    private function profiled(array $columns): Analysis
    {
        $analysis = Analysis::factory()->for($this->actingAsUser())->status(AnalysisStatus::PROFILED)->create(['name' => 'Auction October']);
        foreach ($columns as $i => [$name, $type, $distinct]) {
            DatasetColumn::factory()->for($analysis)->create(['source_name' => $name, 'position' => $i, 'inferred_type' => $type, 'distinct_count' => $distinct]);
        }

        return $analysis;
    }

    public function test_model_suggestions_are_accepted_only_when_compatible_with_the_profiled_values(): void
    {
        $llm = new FakeLlmClient(['columns' => [
            ['column' => 'ซีซี', 'business_name' => 'Engine Size (cc)', 'semantic_type' => 'NUMBER', 'unit' => 'cc', 'aggregation' => 'AVERAGE', 'description' => 'Engine displacement of the vehicle.'],
            ['column' => 'ราคาเปิด', 'business_name' => 'Opening Price', 'semantic_type' => 'CURRENCY', 'unit' => 'THB', 'aggregation' => 'SUM', 'description' => 'Starting bid.'],
            ['column' => 'ทะเบียน', 'business_name' => 'Plate Number', 'semantic_type' => 'IDENTIFIER', 'unit' => '', 'aggregation' => '', 'description' => 'Vehicle registration plate.'],
            ['column' => 'สี', 'business_name' => 'Colour', 'semantic_type' => 'CURRENCY', 'unit' => 'THB', 'aggregation' => 'SUM', 'description' => 'Body colour.'], // text can never be money
            ['column' => 'กม.', 'business_name' => 'ระยะทาง', 'semantic_type' => 'NUMBER', 'unit' => 'km', 'aggregation' => 'AVERAGE', 'description' => 'Mileage.'], // name not English: row dropped
            ['column' => 'sold_price', 'business_name' => 'Sold Price', 'semantic_type' => 'CURRENCY', 'unit' => 'THB', 'aggregation' => 'SUM', 'description' => 'Final hammer price.'],
            ['column' => 'ghost', 'business_name' => 'Ghost', 'semantic_type' => 'TEXT', 'unit' => '', 'aggregation' => '', 'description' => 'x'],
        ]]);
        $this->app->instance(LlmClient::class, $llm);
        $analysis = $this->profiled([['ซีซี', 'INTEGER', 40], ['ราคาเปิด', 'INTEGER', 500], ['ทะเบียน', 'IDENTIFIER', 900], ['สี', 'TEXT', 300], ['กม.', 'INTEGER', 800], ['sold_price', 'INTEGER', 700]]);

        $response = $this->postJson($this->urlFor($analysis, '/definitions/suggest-names'));

        $response->assertOk()
            ->assertJsonPath('meta.ai_status', 'ready')
            ->assertJsonPath('meta.ai_count', 5)
            ->assertJsonPath('data.0', ['column' => 'ซีซี', 'business_name' => 'Engine Size (cc)', 'semantic_type' => 'NUMBER', 'unit' => 'cc', 'aggregation' => 'AVERAGE', 'description' => 'Engine displacement of the vehicle.', 'source' => 'ai'])
            ->assertJsonPath('data.1.semantic_type', 'CURRENCY')->assertJsonPath('data.1.unit', 'THB')
            ->assertJsonPath('data.2.semantic_type', 'IDENTIFIER')->assertJsonPath('data.2.aggregation', null)
            ->assertJsonPath('data.3.business_name', 'Colour')->assertJsonPath('data.3.semantic_type', 'TEXT')->assertJsonPath('data.3.unit', null) // name kept, money rejected
            ->assertJsonPath('data.4', ['column' => 'กม.', 'business_name' => 'กม.', 'semantic_type' => 'NUMBER', 'unit' => null, 'aggregation' => 'SUM', 'description' => null, 'source' => 'software'])
            ->assertJsonPath('data.5.semantic_type', 'CURRENCY')->assertJsonPath('data.5.source', 'ai');
        $this->assertCount(6, $response->json('data'));
        $this->assertSame(1, $llm->calls);
        $this->assertStringContainsString('"column":"ซีซี"', $llm->lastMessages[1]['content']);
        $this->assertStringNotContainsString('rows', strtolower($llm->lastMessages[1]['content']));
    }

    public function test_model_outage_falls_back_to_software_baseline_with_header_hints(): void
    {
        $this->app->instance(LlmClient::class, new FakeLlmClient(null, down: true));
        $analysis = $this->profiled([['revenue_thb', 'NUMERIC', 200], ['discount_pct', 'NUMERIC', 50], ['ซีซี', 'INTEGER', 40], ['customer_id', 'IDENTIFIER', 240]]);

        $this->postJson($this->urlFor($analysis, '/definitions/suggest-names'))->assertOk()
            ->assertJsonPath('meta.ai_status', 'unavailable')
            ->assertJsonPath('data.0', ['column' => 'revenue_thb', 'business_name' => 'Revenue Thb', 'semantic_type' => 'CURRENCY', 'unit' => 'THB', 'aggregation' => 'SUM', 'description' => null, 'source' => 'software'])
            ->assertJsonPath('data.1.semantic_type', 'PERCENTAGE')->assertJsonPath('data.1.unit', '%')->assertJsonPath('data.1.aggregation', 'AVERAGE')
            ->assertJsonPath('data.2.business_name', 'ซีซี')
            ->assertJsonPath('data.3.semantic_type', 'IDENTIFIER');
    }

    public function test_requires_a_profiled_analysis(): void
    {
        $analysis = Analysis::factory()->for($this->actingAsUser())->status(AnalysisStatus::UPLOADED)->create();
        $this->postJson($this->urlFor($analysis, '/definitions/suggest-names'))->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');
    }
}
