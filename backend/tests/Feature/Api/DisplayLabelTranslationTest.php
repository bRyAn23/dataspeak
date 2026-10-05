<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\Evidence;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\DisplayLabelTranslator;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Reporting\NarrativeRenderer;
use App\Services\Validation\RuleBasedClaimValidator;
use Tests\Support\AiScenario;
use Tests\Support\ApiTestCase;
use Tests\Support\FakeLlmClient;

/** FEAT-I18N-002: the report, its chart series and the narrative are English only. */
class DisplayLabelTranslationTest extends ApiTestCase
{
    private function scenarioWithThaiValues(): Analysis
    {
        $analysis = AiScenario::build($this->actingAsUser()); // labels ยอดขาย (revenue) and ဒေသ (region)
        $analysis->forceFill(['status' => AnalysisStatus::ANALYZED])->save();
        $share = Evidence::query()->where('analysis_id', $analysis->id)->where('evidence_key', AiScenario::EV_SHARE)->firstOrFail();
        $share->forceFill(['payload' => ['top_group' => 'ภาคเหนือ', 'top_sum' => 7600000.0, 'total' => 14000000.0, 'unit' => 'THB', 'shares' => ['ภาคเหนือ' => 0.543, 'ภาคใต้' => 0.457], 'round' => 'ลาน 1 (10.30 น.)']])->save();
        $chart = $analysis->visualizations()->first();
        $chart->forceFill(['title' => 'Total ยอดขาย by ဒေသ', 'spec' => ['x' => ['field' => 'group', 'label' => 'ဒေသ'], 'y' => ['field' => 'sum', 'label' => 'ยอดขาย', 'unit' => 'THB'],
            'series' => [['group' => 'ภาคเหนือ', 'sum' => 7600000], ['group' => 'ภาคใต้', 'sum' => 6400000], ['group' => 'รายการประมูลรถจักรยานยนต์ วันอาทิตย์ที่ 4 ตุลาคม 2569 สาขารังสิต ลาน3', 'sum' => 100], ['group' => 'รถจักรยานยนต์', 'sum' => 5]]]])->save();
        $analysis->insights()->first()?->forceFill(['headline' => 'ภาคเหนือ accounts for 54.3% of ยอดขาย across ဒေသ', 'description' => 'Round  ลาน 1  (10.30 น.) leads.'])->save(); // double spaces: analytics text may normalise whitespace
        $analysis->insights()->skip(1)->first()?->forceFill(['headline' => 'Average ยอดขาย is higher for ภาคเหนือ than for รายการประมูลรถจักรยานยนต์ วันอาทิตย์ที่ 4 ตุล'])->save(); // cut at 255 chars when stored

        return $analysis;
    }

    public function test_run_time_map_makes_every_presentation_path_english(): void
    {
        $analysis = $this->scenarioWithThaiValues();
        $llm = new FakeLlmClient(['labels' => [
            ['original' => 'ยอดขาย', 'english' => 'Sales'],
            ['original' => 'ဒေသ', 'english' => 'Region'],
            ['original' => 'ภาคเหนือ', 'english' => 'Northern Region'],
            ['original' => 'ภาคใต้', 'english' => 'ภาคใต้'],          // not English: dropped
            ['original' => 'ลาน 1 (10.30 น.)', 'english' => 'Lot 1 (10.30 am)'],
            ['original' => 'รายการประมูลรถจักรยานยนต์ วันอาทิตย์ที่ 4 ตุลาคม 2569 สาขารังสิต ลาน3', 'english' => 'Motorcycle Auction, Sunday 4 October 2026, Rangsit Lot 3'],
            ['original' => 'รถจักรยานยนต์', 'english' => 'Motorcycle'], // also appears inside the long title above
            ['original' => 'ghost', 'english' => 'Ghost'],            // never asked: ignored
        ]]);
        $this->app->instance(LlmClient::class, $llm);

        $result = app(DisplayLabelTranslator::class)->translate($analysis);

        $this->assertSame(['ai_status' => 'ready', 'model' => 'qwen3:8b', 'count' => 6, 'candidates' => 7], $result);
        $this->assertSame(1, $llm->calls);
        $sent = $llm->lastMessages[1]['content'];
        foreach (['ยอดขาย', 'ဒေသ', 'ภาคเหนือ', 'ภาคใต้'] as $label) {
            $this->assertStringContainsString($label, $sent);
        }
        $this->assertStringNotContainsString('North', $sent); // Latin values are never sent
        $this->assertSame('Northern Region', $analysis->fresh()->label_translations['en']['map']['ภาคเหนือ']);

        // Chart specification as served: title, axis labels and category values in English; numbers untouched
        $this->getJson($this->urlFor($analysis, '/visualizations'))->assertOk()
            ->assertJsonPath('data.0.title', 'Total Sales by Region')
            ->assertJsonPath('data.0.x.label', 'Region')
            ->assertJsonPath('data.0.series.0.group', 'Northern Region')
            ->assertJsonPath('data.0.series.0.sum', 7600000)
            ->assertJsonPath('data.0.series.1.group', 'ภาคใต้'); // untranslated value stays (never invented)
        $this->getJson($this->urlFor($analysis, '/insights'))->assertOk()
            ->assertJsonPath('data.0.headline', 'Northern Region accounts for 54.3% of Sales across Region')
            ->assertJsonPath('data.0.description', 'Round  Lot 1 (10.30 am) leads.') // matched despite the different spacing
            ->assertJsonPath('data.1.headline', 'Average Sales is higher for Northern Region than for Motorcycle Auction, Sunday 4 October 2026, Rangsit Lot 3'); // truncated tail resolved

        // Placeholder views feed the prompt, the validator and the renderer
        $resolver = PlaceholderResolver::for($analysis->fresh());
        $this->assertSame('Northern Region', $resolver->resolve(AiScenario::EV_SHARE, 'group'));
        $this->assertSame('Sales', $resolver->resolve(AiScenario::EV_SHARE, 'label'));
        $template = '{'.AiScenario::EV_SHARE.'.group} accounted for {'.AiScenario::EV_SHARE.'.display_value} of {'.AiScenario::EV_SHARE.'.label} across {'.AiScenario::EV_SHARE.'.dimension}.';
        $this->assertSame('Northern Region accounted for 54.3% of Sales across Region.', (new NarrativeRenderer)->render($template, $resolver));

        // Stored evidence keeps the original value (traceability shows what was measured)
        $this->assertSame('ภาคเหนือ', Evidence::query()->where('evidence_key', AiScenario::EV_SHARE)->value('payload')['top_group'] ?? null);
    }

    public function test_command_rebuilds_the_map_and_outage_keeps_the_run_usable(): void
    {
        $analysis = $this->scenarioWithThaiValues();
        $this->app->instance(LlmClient::class, new FakeLlmClient(null, down: true));

        $this->artisan('dataspeak:translate-labels', ['analysis' => $analysis->public_id])
            ->expectsOutputToContain('0 of 7 labels translated (ai_status unavailable')
            ->assertSuccessful();
        $this->assertSame([], $analysis->fresh()->label_translations['en']['map']);
        $this->getJson($this->urlFor($analysis, '/visualizations'))->assertOk()->assertJsonPath('data.0.series.0.group', 'ภาคเหนือ');

        $this->app->instance(LlmClient::class, new FakeLlmClient(['labels' => [['original' => 'ภาคเหนือ', 'english' => 'Northern Region']]]));
        $this->artisan('dataspeak:translate-labels', ['analysis' => $analysis->public_id, '--show' => true])
            ->expectsOutputToContain('1 of 7 labels translated (ai_status ready')
            ->expectsOutputToContain('ภาคเหนือ -> Northern Region')
            ->assertSuccessful();
    }

    public function test_claims_in_another_script_are_rejected_by_validation(): void
    {
        $analysis = AiScenario::build($this->actingAsUser());
        $resolver = PlaceholderResolver::for($analysis);
        $validator = new RuleBasedClaimValidator;
        $claim = fn (string $text) => ['claim_key' => 'c', 'claim_kind' => 'FINDING', 'text' => $text, 'evidence_ids' => [AiScenario::EV_SHARE], 'confidence_language' => 'descriptive'];

        $thai = $validator->validate($claim('{'.AiScenario::EV_SHARE.'.group} มียอดขาย {'.AiScenario::EV_SHARE.'.display_value} ของทั้งหมด.'), $resolver);
        $this->assertSame('REJECTED', $thai->status->value);
        $this->assertContains('WRONG_LANGUAGE', $thai->reasons);

        $english = $validator->validate($claim('{'.AiScenario::EV_SHARE.'.group} accounted for {'.AiScenario::EV_SHARE.'.display_value} of {'.AiScenario::EV_SHARE.'.label}.'), $resolver);
        $this->assertNotContains('WRONG_LANGUAGE', $english->reasons);
    }
}
