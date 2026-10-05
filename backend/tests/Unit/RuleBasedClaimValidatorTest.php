<?php

namespace Tests\Unit;

use App\Enums\ClaimStatus;
use App\Services\Ai\EvidenceSentences;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Reporting\NarrativeRenderer;
use App\Services\Validation\RuleBasedClaimValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AiScenario;
use Tests\TestCase;

class RuleBasedClaimValidatorTest extends TestCase
{
    use RefreshDatabase;

    private PlaceholderResolver $resolver;

    private RuleBasedClaimValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = PlaceholderResolver::for(AiScenario::build());
        $this->validator = new RuleBasedClaimValidator;
    }

    private function claim(string $text, array $ids, string $kind = 'FINDING'): array
    {
        return ['claim_key' => 'c', 'claim_kind' => $kind, 'text' => $text, 'evidence_ids' => $ids, 'confidence_language' => 'descriptive'];
    }

    public function test_valid_claim_with_thai_and_myanmar_placeholders_is_supported_and_renders(): void
    {
        $text = '{'.AiScenario::EV_SHARE.'.group} accounts for {'.AiScenario::EV_SHARE.'.display_value} of {'.AiScenario::EV_SHARE.'.label} across {'.AiScenario::EV_SHARE.'.dimension}.';
        $result = $this->validator->validate($this->claim($text, [AiScenario::EV_SHARE]), $this->resolver);

        $this->assertSame(ClaimStatus::SUPPORTED, $result->status, implode(',', $result->reasons));
        $this->assertSame('North accounts for 54.3% of ยอดขาย across ဒေသ.', (new NarrativeRenderer)->render($text, $this->resolver));
    }

    public function test_rule_2_and_3_evidence_references(): void
    {
        $unknown = $this->validator->validate($this->claim('{ev_ffffffffffff.display_value} of sales.', ['ev_ffffffffffff']), $this->resolver);
        $this->assertSame(ClaimStatus::REJECTED, $unknown->status);
        $this->assertStringStartsWith('UNKNOWN_EVIDENCE', $unknown->reasons[0]);

        $none = $this->validator->validate($this->claim('Sales were strong across regions.', []), $this->resolver);
        $this->assertSame(ClaimStatus::REJECTED, $none->status);
        $this->assertContains('NO_EVIDENCE_REFERENCE', $none->reasons);
    }

    public function test_rule_4_digits_outside_placeholders(): void
    {
        $r = $this->validator->validate($this->claim('Revenue reached 14.1 million THB in 2025.', [AiScenario::EV_MEAN]), $this->resolver);
        $this->assertSame(ClaimStatus::REJECTED, $r->status);
        $this->assertContains('DIGITS_OUTSIDE_PLACEHOLDER', $r->reasons);

        $thaiDigits = $this->validator->validate($this->claim('ยอดขาย ๒๕ ล้านบาท ตามหลักฐาน', [AiScenario::EV_MEAN]), $this->resolver);
        $this->assertContains('DIGITS_OUTSIDE_PLACEHOLDER', $thaiDigits->reasons);

        $ok = $this->validator->validate($this->claim('Average {'.AiScenario::EV_MEAN.'.label} was {'.AiScenario::EV_MEAN.'.display_value}.', [AiScenario::EV_MEAN]), $this->resolver);
        $this->assertSame(ClaimStatus::SUPPORTED, $ok->status);
    }

    public function test_rule_5_placeholder_whitelist_and_resolvability(): void
    {
        $field = $this->validator->validate($this->claim('Value {'.AiScenario::EV_MEAN.'.value} was observed.', [AiScenario::EV_MEAN]), $this->resolver);
        $this->assertContains('PLACEHOLDER_FIELD_NOT_ALLOWED:value', $field->reasons);

        $unresolvable = $this->validator->validate($this->claim('Top group {'.AiScenario::EV_MEAN.'.group} led the mean.', [AiScenario::EV_MEAN]), $this->resolver);
        $this->assertContains('PLACEHOLDER_UNRESOLVABLE:'.AiScenario::EV_MEAN.'.group', $unresolvable->reasons);
        $this->assertSame(ClaimStatus::REJECTED, $unresolvable->status);
    }

    public function test_rule_6_causal_language_is_flagged_for_review(): void
    {
        $r = $this->validator->validate($this->claim('North led because of strong demand, with {'.AiScenario::EV_SHARE.'.display_value} of sales.', [AiScenario::EV_SHARE]), $this->resolver);
        $this->assertSame(ClaimStatus::NEEDS_REVIEW, $r->status);
        $this->assertContains('CAUSAL_LANGUAGE:because_of', $r->reasons);
    }

    public function test_rule_7_quality_only_findings_are_reclassified_as_caveats(): void
    {
        $warning = $this->validator->validate($this->claim('{'.AiScenario::EV_NULLS.'.label} is missing in {'.AiScenario::EV_NULLS.'.display_value} of rows.', [AiScenario::EV_NULLS]), $this->resolver);
        $this->assertSame(ClaimStatus::SUPPORTED, $warning->status);
        $this->assertContains('RECLASSIFIED_AS_CAVEAT', $warning->reasons);
        $this->assertSame('CAVEAT', $warning->claim['claim_kind']);

        $mixed = $this->validator->validate($this->claim('Average {'.AiScenario::EV_MEAN.'.label} was {'.AiScenario::EV_MEAN.'.display_value} despite {'.AiScenario::EV_NULLS.'.display_value} missing.', [AiScenario::EV_MEAN, AiScenario::EV_NULLS]), $this->resolver);
        $this->assertSame('FINDING', $mixed->claim['claim_kind']); // acceptable evidence present: stays a finding
        $this->assertSame(ClaimStatus::SUPPORTED, $mixed->status);

        $caveat = $this->validator->validate($this->claim('{'.AiScenario::EV_NULLS.'.label} is missing in {'.AiScenario::EV_NULLS.'.display_value} of rows.', [AiScenario::EV_NULLS], 'CAVEAT'), $this->resolver);
        $this->assertSame(ClaimStatus::SUPPORTED, $caveat->status);

        $insufficient = $this->validator->validate($this->claim('The trend for {'.AiScenario::EV_INSUFF.'.label} could not be established.', [AiScenario::EV_INSUFF]), $this->resolver);
        $this->assertSame('CAVEAT', $insufficient->claim['claim_kind']);
        $this->assertSame(ClaimStatus::SUPPORTED, $insufficient->status);
    }

    public function test_dataset_mechanics_only_summary_becomes_a_caveat(): void
    {
        $row = $this->resolver->all()->first()->analysis->evidence()->create(['evidence_key' => 'ev_aaaaaaaaaaa9', 'analysis_family' => 'DESCRIPTIVE', 'metric' => 'row_count', 'method' => 'count_rows', 'value' => 240, 'display_value' => '240', 'source_columns' => [], 'parameters' => [], 'quality_status' => 'ACCEPTABLE']);
        $resolver = PlaceholderResolver::for($row->analysis->fresh());
        $r = $this->validator->validate($this->claim('The dataset contains {ev_aaaaaaaaaaa9.display_value} rows.', ['ev_aaaaaaaaaaa9'], 'EXECUTIVE_SUMMARY'), $resolver);
        $this->assertSame('CAVEAT', $r->claim['claim_kind']);
        $this->assertContains('RECLASSIFIED_AS_CAVEAT', $r->reasons);
        $this->assertSame(ClaimStatus::SUPPORTED, $r->status);
    }

    public function test_semantic_guards_flag_placeholder_misuse_for_review(): void
    {
        $repeated = $this->validator->validate($this->claim('{'.AiScenario::EV_SHARE.'.label} accounted for {'.AiScenario::EV_SHARE.'.display_value} of {'.AiScenario::EV_SHARE.'.label}.', [AiScenario::EV_SHARE]), $this->resolver);
        $this->assertSame(ClaimStatus::NEEDS_REVIEW, $repeated->status);
        $this->assertContains('REPEATED_PLACEHOLDER:'.AiScenario::EV_SHARE.'.label', $repeated->reasons);

        $noGroup = $this->validator->validate($this->claim('The leading region accounted for {'.AiScenario::EV_SHARE.'.display_value} of {'.AiScenario::EV_SHARE.'.label}.', [AiScenario::EV_SHARE]), $this->resolver);
        $this->assertSame(ClaimStatus::NEEDS_REVIEW, $noGroup->status);
        $this->assertContains('GROUP_NOT_NAMED:'.AiScenario::EV_SHARE, $noGroup->reasons);

        $good = $this->validator->validate($this->claim('{'.AiScenario::EV_SHARE.'.group} accounted for {'.AiScenario::EV_SHARE.'.display_value} of {'.AiScenario::EV_SHARE.'.label} across {'.AiScenario::EV_SHARE.'.dimension}.', [AiScenario::EV_SHARE]), $this->resolver);
        $this->assertSame(ClaimStatus::SUPPORTED, $good->status);
    }

    public function test_software_sentences_validate_as_supported(): void
    {
        foreach ($this->resolver->all() as $key => $evidence) {
            $sentence = EvidenceSentences::for($evidence, $this->resolver);
            if ($sentence === null) {
                continue;
            }
            $kind = in_array($evidence->metric, RuleBasedClaimValidator::MECHANICS_METRICS, true) ? 'CAVEAT' : 'FINDING';
            $r = $this->validator->validate($this->claim($sentence, [$key], $kind), $this->resolver);
            $this->assertNotSame(ClaimStatus::REJECTED, $r->status, $sentence.' -> '.implode(',', $r->reasons));
        }
        $share = EvidenceSentences::for($this->resolver->evidence(AiScenario::EV_SHARE), $this->resolver);
        $this->assertSame('North accounted for 54.3% of ยอดขาย across ဒေသ.', (new NarrativeRenderer)->render($share, $this->resolver));
        $drop = EvidenceSentences::for($this->resolver->evidence(AiScenario::EV_DROP), $this->resolver);
        $this->assertStringContainsString(' fell ', $drop);
    }

    public function test_placeholders_complete_the_citation_list(): void
    {
        $r = $this->validator->validate($this->claim('{'.AiScenario::EV_SHARE.'.group} leads with {'.AiScenario::EV_SHARE.'.display_value}; the average was {'.AiScenario::EV_MEAN.'.display_value}.', [AiScenario::EV_SHARE]), $this->resolver);
        $this->assertSame(ClaimStatus::SUPPORTED, $r->status);
        $this->assertContains('CITATION_COMPLETED:'.AiScenario::EV_MEAN, $r->reasons);
        $this->assertEqualsCanonicalizing([AiScenario::EV_SHARE, AiScenario::EV_MEAN], $r->claim['evidence_ids']);
    }

    public function test_rule_8_direction_contradiction(): void
    {
        $r = $this->validator->validate($this->claim('Revenue increased by {'.AiScenario::EV_DROP.'.display_value} in the latest month.', [AiScenario::EV_DROP]), $this->resolver);
        $this->assertSame(ClaimStatus::REJECTED, $r->status);
        $this->assertContains('DIRECTION_CONTRADICTION:'.AiScenario::EV_DROP, $r->reasons);

        $ok = $this->validator->validate($this->claim('Revenue fell by {'.AiScenario::EV_DROP.'.display_value} from {'.AiScenario::EV_DROP.'.period_from} to {'.AiScenario::EV_DROP.'.period_to}.', [AiScenario::EV_DROP]), $this->resolver);
        $this->assertSame(ClaimStatus::SUPPORTED, $ok->status, implode(',', $ok->reasons));
    }
}
