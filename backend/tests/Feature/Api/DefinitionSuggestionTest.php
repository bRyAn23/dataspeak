<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\DatasetColumn;
use App\Services\Ai\ColumnDefinitionSuggester;
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
            ['column' => 'ซีซี', 'business_name' => 'Engine Size (cc)', 'semantic_type' => 'NUMBER', 'unit' => 'cc', 'aggregation' => 'AVERAGE', 'description' => 'Engine displacement of the vehicle.', 'reason' => 'Engine size describes each car, so it is averaged rather than totalled.'],
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
            ->assertJsonPath('data.0', ['column' => 'ซีซี', 'business_name' => 'Engine Size (cc)', 'semantic_type' => 'NUMBER', 'unit' => 'cc', 'aggregation' => 'AVERAGE', 'description' => null, 'reason' => 'Engine size describes each car, so it is averaged rather than totalled.', 'source' => 'ai'])
            ->assertJsonPath('data.5.reason', null) // the model gave no reason for this row: no software hint either
            ->assertJsonPath('data.1.semantic_type', 'CURRENCY')->assertJsonPath('data.1.unit', 'THB')
            ->assertJsonPath('data.2.semantic_type', 'IDENTIFIER')->assertJsonPath('data.2.aggregation', null)
            ->assertJsonPath('data.3.business_name', 'Colour')->assertJsonPath('data.3.semantic_type', 'TEXT')->assertJsonPath('data.3.unit', null) // name kept, money rejected
            ->assertJsonPath('data.4', ['column' => 'กม.', 'business_name' => 'กม.', 'semantic_type' => 'NUMBER', 'unit' => null, 'aggregation' => 'SUM', 'description' => null, 'reason' => 'Detected as a quantity that adds up across records; change it if it is a property of one item.', 'source' => 'software'])
            ->assertJsonPath('data.5.semantic_type', 'CURRENCY')->assertJsonPath('data.5.source', 'ai');
        $this->assertCount(6, $response->json('data'));
        $this->assertSame(1, $llm->calls);
        $this->assertStringContainsString('"column":"ซีซี"', $llm->lastMessages[1]['content']);
        $this->assertStringNotContainsString('rows', strtolower($llm->lastMessages[1]['content']));
    }

    public function test_model_outage_falls_back_to_software_baseline_with_header_hints(): void
    {
        $this->app->instance(LlmClient::class, new FakeLlmClient(null, down: true));
        $analysis = $this->profiled([['revenue_thb', 'NUMERIC', 200], ['discount_pct', 'NUMERIC', 50], ['ซีซี', 'INTEGER', 40], ['customer_id', 'IDENTIFIER', 240], ['engine_cc', 'INTEGER', 40], ['production_year', 'INTEGER', 30]]);

        $this->postJson($this->urlFor($analysis, '/definitions/suggest-names'))->assertOk()
            ->assertJsonPath('meta.ai_status', 'unavailable')
            ->assertJsonPath('data.0', ['column' => 'revenue_thb', 'business_name' => 'Revenue Thb', 'semantic_type' => 'CURRENCY', 'unit' => 'THB', 'aggregation' => 'SUM', 'description' => null, 'reason' => 'Detected as a quantity that adds up across records; change it if it is a property of one item.', 'source' => 'software'])
            ->assertJsonPath('data.1.semantic_type', 'PERCENTAGE')->assertJsonPath('data.1.unit', '%')->assertJsonPath('data.1.aggregation', 'AVERAGE')
            ->assertJsonPath('data.2.business_name', 'ซีซี')
            ->assertJsonPath('data.3.semantic_type', 'IDENTIFIER')
            // Specifications of one item are never totalled, even without the model
            ->assertJsonPath('data.4.aggregation', 'AVERAGE')->assertJsonPath('data.4.reason', 'The header names a property of each record, so it is averaged rather than totalled.')
            ->assertJsonPath('data.5.aggregation', 'AVERAGE');
    }

    public function test_the_reason_is_stored_with_the_suggested_definition_and_shown_on_the_profile(): void
    {
        $this->app->instance(LlmClient::class, new FakeLlmClient(['columns' => [
            ['column' => 'engine_cc', 'business_name' => 'Engine Size (cc)', 'semantic_type' => 'NUMBER', 'unit' => 'cc', 'aggregation' => 'AVERAGE', 'description' => 'Engine displacement.', 'reason' => 'Engine size describes each car, so it is averaged rather than totalled.'],
        ]]));
        $analysis = $this->profiled([['engine_cc', 'INTEGER', 40]]);
        app(ColumnDefinitionSuggester::class)->apply($analysis);

        $this->assertSame('Engine size describes each car, so it is averaged rather than totalled.', $analysis->columns()->first()->definition->suggestion_reason);
        $this->getJson($this->urlFor($analysis, '/profile'))->assertOk()
            ->assertJsonPath('data.columns.0.definition.aggregation', 'AVERAGE')
            ->assertJsonPath('data.columns.0.definition.suggestion_reason', 'Engine size describes each car, so it is averaged rather than totalled.');
    }

    public function test_wide_files_are_sent_in_concurrent_batches_and_rows_are_matched_by_index(): void
    {
        // 14 columns -> two batches of 12 and 2; the second answer alters the header text but keeps the index
        $cols = array_map(fn ($i) => ["col_{$i}", 'INTEGER', 100], range(1, 14));
        $first = array_map(fn ($i) => ['index' => $i, 'column' => "col_{$i}", 'business_name' => "Column {$i}", 'semantic_type' => 'NUMBER', 'unit' => '', 'aggregation' => 'AVERAGE', 'reason' => 'A property of each record.'], range(1, 12));
        $second = [['index' => 1, 'column' => 'COL 13 (edited by the model)', 'business_name' => 'Thirteen', 'semantic_type' => 'NUMBER', 'unit' => '', 'aggregation' => 'SUM', 'reason' => 'Adds up.'],
            ['index' => 2, 'column' => 'col_14', 'business_name' => 'Fourteen', 'semantic_type' => 'NUMBER', 'unit' => '', 'aggregation' => 'AVERAGE', 'reason' => 'A level.']];
        $llm = new FakeLlmClient(['columns' => []], false, [['columns' => $first], ['columns' => $second]]);
        $this->app->instance(LlmClient::class, $llm);
        $analysis = $this->profiled($cols);

        $response = $this->postJson($this->urlFor($analysis, '/definitions/suggest-names'))->assertOk()->assertJsonPath('meta.ai_count', 14);
        $this->assertSame(2, $llm->calls);
        $this->assertSame('Thirteen', collect($response->json('data'))->firstWhere('column', 'col_13')['business_name']);
        $this->assertSame('Fourteen', collect($response->json('data'))->firstWhere('column', 'col_14')['business_name']);
        $this->assertStringContainsString('"index":1', $llm->lastMessages[1]['content']);
    }

    public function test_requires_a_profiled_analysis(): void
    {
        $analysis = Analysis::factory()->for($this->actingAsUser())->status(AnalysisStatus::UPLOADED)->create();
        $this->postJson($this->urlFor($analysis, '/definitions/suggest-names'))->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');
    }
}
