<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Jobs\GenerateReportJob;
use App\Models\Evidence;
use App\Services\Ai\AiGateway;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\DisplayLabelTranslator;
use App\Services\Ai\EvidenceSentences;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Analytics\ProgressStore;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Services\Reporting\NarrativeRenderer;
use App\Services\Reports\ChartTitles;
use App\Services\Reports\ReportAssembler;
use App\Services\Validation\Contracts\ClaimValidator;
use App\Services\Validation\RuleBasedClaimValidator;
use App\Support\Locale;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AiScenario;
use Tests\Support\ApiTestCase;
use Tests\Support\FakeLlmClient;

/** FEAT-I18N-003: the report (narrative, labels, chart titles, PDF) in the language the user chose. */
class ReportLanguageTest extends ApiTestCase
{
    public function test_locale_helpers_detect_scripts(): void
    {
        $this->assertSame('th', Locale::normalize('th-TH'));
        $this->assertSame('en', Locale::normalize('fr'));
        $this->assertTrue(Locale::inLanguage('ยอดขายเพิ่มขึ้น {ev_aaaaaaaaaaa1.display_value}', 'th'));
        $this->assertFalse(Locale::inLanguage('Sales went up', 'th'));            // English in a Thai report
        $this->assertFalse(Locale::inLanguage('ยอดขาย 增加', 'th'));                 // a third script
        $this->assertTrue(Locale::inLanguage('销售额上升了 (THB)', 'zh'));
        $this->assertTrue(Locale::inLanguage('ရောင်းအား တိုးလာသည်။', 'my'));
        $this->assertTrue(Locale::inLanguage('+3.2%', 'th'));                      // no letters at all
    }

    public function test_sentences_chart_titles_and_period_names_are_localised(): void
    {
        $analysis = AiScenario::build($this->actingAsUser(), revenueLabel: 'Revenue', regionLabel: 'Region');
        $resolver = PlaceholderResolver::for($analysis, 'th');
        $share = Evidence::query()->where('analysis_id', $analysis->id)->where('evidence_key', AiScenario::EV_SHARE)->firstOrFail();

        $sentence = EvidenceSentences::for($share, $resolver);
        $this->assertSame('North คิดเป็น 54.3% ของ Revenue ทั้งหมดเมื่อแบ่งตาม Region', (new NarrativeRenderer)->render($sentence, $resolver));
        $this->assertSame('ยอดขาย รวม แยกตาม ภูมิภาค', ChartTitles::localize('Total ยอดขาย by ภูมิภาค', 'th'));
        $this->assertSame('按月统计的 Revenue', ChartTitles::localize('Revenue by month', 'zh'));
        $this->assertSame('Revenue ၏ ဖြန့်ကျက်မှု', ChartTitles::localize('Distribution of Revenue', 'my'));
        $this->assertSame('Total Revenue by Region', ChartTitles::localize('Total Revenue by Region', 'en'));
        $this->assertSame('Something custom', ChartTitles::localize('Something custom', 'th'));
    }

    public function test_claims_must_be_in_the_report_language(): void
    {
        $analysis = AiScenario::build($this->actingAsUser());
        $resolver = PlaceholderResolver::for($analysis, 'th');
        $validator = new RuleBasedClaimValidator;
        $claim = fn (string $text) => ['claim_key' => 'c', 'claim_kind' => 'FINDING', 'text' => $text, 'evidence_ids' => [AiScenario::EV_SHARE], 'confidence_language' => 'descriptive'];

        $thai = $validator->validate($claim('{'.AiScenario::EV_SHARE.'.group} คิดเป็น {'.AiScenario::EV_SHARE.'.display_value} ของ {'.AiScenario::EV_SHARE.'.label}'), $resolver);
        $this->assertSame('SUPPORTED', $thai->status->value, implode(',', $thai->reasons));

        $english = $validator->validate($claim('{'.AiScenario::EV_SHARE.'.group} accounted for {'.AiScenario::EV_SHARE.'.display_value} of {'.AiScenario::EV_SHARE.'.label}.'), $resolver);
        $this->assertContains('WRONG_LANGUAGE', $english->reasons);

        $causal = $validator->validate($claim('{'.AiScenario::EV_SHARE.'.group} สูงเพราะ {'.AiScenario::EV_SHARE.'.label}'), $resolver);
        $this->assertStringStartsWith('CAUSAL_LANGUAGE', collect($causal->reasons)->first(fn ($r) => str_starts_with($r, 'CAUSAL')) ?? '');
    }

    public function test_language_header_localises_api_resources_and_run_records_the_language(): void
    {
        Queue::fake();
        $analysis = AiScenario::build($this->actingAsUser(), revenueLabel: 'Revenue', regionLabel: 'Region');
        $analysis->forceFill(['status' => AnalysisStatus::ANALYZED])->save();
        $analysis->visualizations()->first()->forceFill(['title' => 'Total Revenue by Region'])->save();

        $this->getJson($this->urlFor($analysis, '/visualizations'), ['X-Dataspeak-Language' => 'th'])->assertOk()
            ->assertJsonPath('data.0.title', 'Revenue รวม แยกตาม Region');
        $this->getJson($this->urlFor($analysis, '/insights'), ['X-Dataspeak-Language' => 'zh'])->assertOk()
            ->assertJsonPath('data.0.headline', 'North 占 Revenue 总量的 54.3%（按 Region 划分）。');
        $this->getJson($this->urlFor($analysis, '/visualizations'))->assertOk()->assertJsonPath('data.0.title', 'Total Revenue by Region');

        $this->postJson($this->urlFor($analysis, '/report'), ['language' => 'xx'], ['Idempotency-Key' => 'k-xx'])->assertStatus(422);
        $this->postJson($this->urlFor($analysis, '/report'), ['language' => 'my'], ['Idempotency-Key' => 'k-my'])->assertStatus(202);
        Queue::assertPushed(GenerateReportJob::class, fn ($job) => $job->language === 'my');
        $this->assertSame('my', $analysis->fresh()->report_language);
    }

    public function test_thai_report_end_to_end_including_pdf(): void
    {
        Http::fake(); // Ollama HTTP is never reached: the LLM is the in-process fake below
        $analysis = AiScenario::build($this->actingAsUser(), revenueLabel: 'Revenue', regionLabel: 'Region');
        $analysis->forceFill(['status' => AnalysisStatus::REPORTING, 'report_language' => 'th'])->save();
        $analysis->visualizations()->first()->forceFill(['title' => 'Total Revenue by Region'])->save();
        $share = AiScenario::EV_SHARE;
        $labels = ['labels' => [['original' => 'Revenue', 'english' => 'ยอดขาย'], ['original' => 'Region', 'english' => 'ภูมิภาค'], ['original' => 'North', 'english' => 'ภาคเหนือ'], ['original' => 'Order Date', 'english' => 'วันที่สั่งซื้อ']]];
        $llm = new FakeLlmClient(['claims' => [], 'limitations' => []], false, [$labels, [
            'claims' => [
                ['claim_key' => 'c1', 'claim_kind' => 'EXECUTIVE_SUMMARY', 'text' => '{'.$share.'.group} คิดเป็น {'.$share.'.display_value} ของ {'.$share.'.label} ทั้งหมดเมื่อแบ่งตาม {'.$share.'.dimension}', 'evidence_ids' => [$share], 'confidence_language' => 'descriptive'],
                ['claim_key' => 'c2', 'claim_kind' => 'FINDING', 'text' => 'Revenue is concentrated in {'.$share.'.group}.', 'evidence_ids' => [$share], 'confidence_language' => 'descriptive'],
            ], 'limitations' => []]]); // first call translates labels, second writes the claims, any repair gets nothing
        $this->app->instance(LlmClient::class, $llm);

        (new GenerateReportJob($analysis->id, 'job_th', 'th'))->handle(app(AiGateway::class), app(ClaimValidator::class), app(ReportAssembler::class), app(AnalysisLifecycle::class), app(ProgressStore::class));

        $fresh = $analysis->fresh();
        $this->assertSame(AnalysisStatus::COMPLETED, $fresh->status);
        $this->assertSame('ภาคเหนือ', $fresh->label_translations['th']['map']['North']);
        // Read with the interface in Thai: narrative and software-written parts agree.
        $response = $this->getJson($this->urlFor($analysis, '/report'), ['X-Dataspeak-Language' => 'th'])->assertOk()
            ->assertJsonPath('data.language', 'th')
            ->assertJsonPath('data.display_language', 'th')
            ->assertJsonPath('data.sections.0.title', 'บทสรุปผู้บริหาร');
        $statements = collect($response->json('data.claims'))->pluck('statement')->all();
        $this->assertContains('ภาคเหนือ คิดเป็น 54.3% ของ ยอดขาย ทั้งหมดเมื่อแบ่งตาม ภูมิภาค', $statements);
        $this->assertNotContains('Revenue is concentrated in ภาคเหนือ.', $statements); // WRONG_LANGUAGE, rejected
        $this->assertSame('ยอดขาย (เฉลี่ย)', collect($response->json('data.summary_metrics'))->firstWhere('evidence_key', AiScenario::EV_MEAN)['label']);
        $this->assertSame('ยอดขาย รวม แยกตาม ภูมิภาค', $response->json('data.visualizations.0.title'));

        // The interface moved to Chinese: software-written parts follow, the Thai narrative stays until rewritten.
        $zh = $this->getJson($this->urlFor($analysis, '/report'), ['X-Dataspeak-Language' => 'zh'])->assertOk()
            ->assertJsonPath('data.language', 'th')
            ->assertJsonPath('data.display_language', 'zh')
            ->assertJsonPath('data.visualizations.0.title', '按 Region 划分的 Revenue 总计') // no zh label map yet: labels stay English
            ->assertJsonPath('data.summary_metrics.0.label', 'Revenue平均');
        $this->assertContains('ภาคเหนือ คิดเป็น 54.3% ของ ยอดขาย ทั้งหมดเมื่อแบ่งตาม ภูมิภาค', collect($zh->json('data.claims'))->pluck('statement')->all());

        $pdf = $this->get($this->urlFor($analysis, '/report/pdf'));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_translator_targets_the_requested_language(): void
    {
        $analysis = AiScenario::build($this->actingAsUser(), revenueLabel: 'Revenue', regionLabel: 'Region');
        $translator = app(DisplayLabelTranslator::class);
        $this->assertSame([], $translator->candidates($analysis, 'en'));                  // already English
        $this->assertContains('Revenue', $translator->candidates($analysis, 'zh'));         // needs Chinese
        $this->assertContains('North', $translator->candidates($analysis, 'my'));
        $this->assertTrue(DisplayLabelTranslator::needsTranslation('Order Date', 'th'));
        $this->assertFalse(DisplayLabelTranslator::needsTranslation('2025-01', 'th'));
        $this->assertFalse(DisplayLabelTranslator::needsTranslation('ยอดขาย (THB)', 'th'));
    }
}
