<?php

namespace Tests\Unit;

use App\Services\Ai\Schemas\ClaimOutputSchema;
use PHPUnit\Framework\TestCase;
use Tests\Support\AiScenario;

class ClaimOutputSchemaTest extends TestCase
{
    public function test_valid_output_has_no_problems(): void
    {
        $this->assertSame([], ClaimOutputSchema::problems(AiScenario::modelOutput()));
    }

    public function test_structural_problems_are_reported(): void
    {
        $this->assertNotEmpty(ClaimOutputSchema::problems('not an object'));
        $this->assertNotEmpty(ClaimOutputSchema::problems(['claims' => []]));
        $bad = AiScenario::modelOutput();
        $bad['claims'][0]['claim_kind'] = 'METRIC';
        $bad['claims'][1]['evidence_ids'] = [];
        $bad['claims'][2]['extra'] = 'x';
        unset($bad['claims'][3]['confidence_language']);
        $bad['claims'][4]['evidence_ids'] = ['not-an-id'];
        $problems = ClaimOutputSchema::problems($bad);
        $this->assertCount(5, $problems, implode("\n", $problems));
    }

    public function test_schema_matches_parser_expectations(): void
    {
        $schema = ClaimOutputSchema::schema();
        $this->assertSame(['claims', 'limitations'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['EXECUTIVE_SUMMARY', 'FINDING', 'CAVEAT'], $schema['properties']['claims']['items']['properties']['claim_kind']['enum']);
    }
}
