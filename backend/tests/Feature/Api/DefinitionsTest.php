<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\DatasetColumn;
use Tests\Support\ApiTestCase;

/** DEF-API-001, FEAT-DEF-001/002 */
class DefinitionsTest extends ApiTestCase
{
    private function profiled(): Analysis
    {
        $analysis = Analysis::factory()->for($this->actingAsUser())->status(AnalysisStatus::PROFILED)->create();
        DatasetColumn::factory()->for($analysis)->create(['source_name' => 'revenue_thb', 'position' => 0]);
        DatasetColumn::factory()->for($analysis)->create(['source_name' => 'region', 'position' => 1]);

        return $analysis;
    }

    public function test_saving_definitions_moves_to_defined(): void
    {
        $analysis = $this->profiled();

        $this->putJson($this->urlFor($analysis, '/definitions'), ['definitions' => [
            ['column' => 'revenue_thb', 'business_name' => 'Revenue', 'semantic_type' => 'CURRENCY', 'unit' => 'THB', 'aggregation' => 'SUM'],
            ['column' => 'region', 'business_name' => 'Sales Region', 'semantic_type' => 'CATEGORY'],
        ]])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.unit', 'THB')
            ->assertJsonPath('meta.status', 'DEFINED');

        $this->assertDatabaseHas('field_definitions', ['business_name' => 'Sales Region', 'semantic_type' => 'CATEGORY']);
    }

    public function test_business_names_must_be_english(): void
    {
        $analysis = $this->profiled();

        $this->putJson($this->urlFor($analysis, '/definitions'), ['definitions' => [
            ['column' => 'revenue_thb', 'business_name' => 'ยอดขาย', 'semantic_type' => 'CURRENCY', 'unit' => 'THB'],
            ['column' => 'region', 'business_name' => 'Sales Region', 'semantic_type' => 'CATEGORY'],
        ]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonFragment(['field' => 'definitions.0.business_name', 'issue' => 'Business names must be in English because the report is written in English.']);
        $this->assertSame(AnalysisStatus::PROFILED, $analysis->fresh()->status);
    }

    public function test_unknown_column_and_invalid_enum_are_rejected(): void
    {
        $analysis = $this->profiled();

        $this->putJson($this->urlFor($analysis, '/definitions'), ['definitions' => [
            ['column' => 'ghost', 'business_name' => 'Ghost', 'semantic_type' => 'NUMBER'],
        ]])->assertStatus(422)->assertJsonPath('error.code', 'UNKNOWN_COLUMN');

        $this->putJson($this->urlFor($analysis, '/definitions'), ['definitions' => [
            ['column' => 'region', 'business_name' => 'Region', 'semantic_type' => 'Dimension'],
        ]])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->putJson($this->urlFor($analysis, '/definitions'), ['definitions' => [
            ['column' => 'revenue_thb', 'business_name' => 'Revenue', 'semantic_type' => 'CURRENCY'],
        ]])->assertStatus(422)->assertJsonFragment(['field' => 'definitions.0.unit']);
    }

    public function test_definitions_require_a_profiled_analysis(): void
    {
        $analysis = Analysis::factory()->for($this->actingAsUser())->status(AnalysisStatus::UPLOADED)->create();
        $this->putJson($this->urlFor($analysis, '/definitions'), ['definitions' => [['column' => 'x', 'business_name' => 'X', 'semantic_type' => 'NUMBER']]])
            ->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');
    }
}
