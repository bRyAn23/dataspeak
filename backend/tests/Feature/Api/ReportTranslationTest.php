<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Jobs\GenerateReportJob;
use App\Jobs\TranslateReportJob;
use App\Models\Analysis;
use App\Models\Claim;
use App\Services\Ai\AiGateway;
use App\Services\Ai\ClaimTranslator;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\DisplayLabelTranslator;
use App\Services\Analytics\ProgressStore;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Services\Reports\DisplayLabels;
use App\Services\Reports\Pdf\ChartHtml;
use App\Services\Reports\ReportAssembler;
use App\Services\Validation\Contracts\ClaimValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AiScenario;
use Tests\Support\ApiTestCase;
use Tests\Support\FakeLlmClient;

/** RPT-API-004 / FEAT-I18N-003: an existing verified report shown in another language by translation, not re-interpretation. */
class ReportTranslationTest extends ApiTestCase
{
    private function englishReport(): Analysis
    {
        Http::fake();
        $analysis = AiScenario::build($this->actingAsUser(), revenueLabel: 'Revenue', regionLabel: 'Region');
        $analysis->forceFill(['status' => AnalysisStatus::REPORTING, 'report_language' => 'en'])->save();
        $share = AiScenario::EV_SHARE;
        $this->app->instance(LlmClient::class, new FakeLlmClient(['claims' => [
            ['claim_key' => 'c1', 'claim_kind' => 'EXECUTIVE_SUMMARY', 'text' => '{'.$share.'.group} accounted for {'.$share.'.display_value} of {'.$share.'.label} across {'.$share.'.dimension}.', 'evidence_ids' => [$share], 'confidence_language' => 'descriptive'],
            ['claim_key' => 'c2', 'claim_kind' => 'FINDING', 'text' => '{'.AiScenario::EV_GROWTH.'.label} grew {'.AiScenario::EV_GROWTH.'.display_value} per {'.AiScenario::EV_GROWTH.'.period} on average.', 'evidence_ids' => [AiScenario::EV_GROWTH], 'confidence_language' => 'descriptive'],
        ], 'limitations' => []]));
        (new GenerateReportJob($analysis->id, 'job_en', 'en'))->handle(app(AiGateway::class), app(ClaimValidator::class), app(ReportAssembler::class), app(AnalysisLifecycle::class), app(ProgressStore::class));
        $this->assertSame(AnalysisStatus::COMPLETED, $analysis->fresh()->status);

        return $analysis->fresh();
    }

    public function test_translation_keeps_placeholders_and_rejects_bad_sentences(): void
    {
        $analysis = $this->englishReport();
        $share = AiScenario::EV_SHARE;
        [$c1, $c2] = $analysis->claims()->where('in_report', true)->orderBy('position')->get()->all();

        $this->app->instance(LlmClient::class, new FakeLlmClient(['claims' => []], false, [
            ['labels' => [['original' => 'Revenue', 'english' => 'ยอดขาย'], ['original' => 'Region', 'english' => 'ภูมิภาค'], ['original' => 'North', 'english' => 'ภาคเหนือ'], ['original' => 'Order Date', 'english' => 'วันที่สั่งซื้อ']]],
            ['claims' => [
                ['id' => $c1->public_id, 'text' => '{'.$share.'.group} คิดเป็น {'.$share.'.display_value} ของ {'.$share.'.label} เมื่อแบ่งตาม {'.$share.'.dimension}'],
                ['id' => $c2->public_id, 'text' => '{'.AiScenario::EV_GROWTH.'.label} เติบโต 3.2% ต่อ{'.AiScenario::EV_GROWTH.'.period}'], // a placeholder replaced by a number: rejected
            ]],
        ]));

        $this->postJson($this->urlFor($analysis, '/report/translate'), ['language' => 'th'])->assertOk()
            ->assertJsonPath('data.language', 'th')->assertJsonPath('data.report_language', 'en')
            ->assertJsonPath('data.translated', 1)->assertJsonPath('data.pending', 1)->assertJsonPath('data.total', 2)->assertJsonPath('data.ai_status', 'ready');
        $this->assertSame('{'.$share.'.group} คิดเป็น {'.$share.'.display_value} ของ {'.$share.'.label} เมื่อแบ่งตาม {'.$share.'.dimension}', $c1->fresh()->statement_translations['th']);
        $this->assertNull($c2->fresh()->statement_translations);

        // Thai interface: translated claim in Thai with Thai labels; the untranslated one stays English, never mixed
        $th = $this->getJson($this->urlFor($analysis, '/report'), ['X-Dataspeak-Language' => 'th'])->assertOk()
            ->assertJsonPath('data.language', 'en')->assertJsonPath('data.display_language', 'th')
            ->assertJsonPath('data.translation.complete', false)->assertJsonPath('data.translation.translated', 1);
        $statements = collect($th->json('data.claims'))->pluck('statement')->all();
        $this->assertContains('ภาคเหนือ คิดเป็น 54.3% ของ ยอดขาย เมื่อแบ่งตาม ภูมิภาค', $statements);
        $this->assertTrue(collect($statements)->contains(fn ($st) => str_contains($st, 'grew')), 'the untranslated claim stays in English'); // never a mixed sentence
        $this->assertSame('ภาคเหนือ คิดเป็น 54.3% ของ ยอดขาย เมื่อแบ่งตาม ภูมิภาค', collect($th->json('data.visualizations.0.summary'))->firstWhere('source', 'narrative')['text'] ?? null);
        // English interface: untouched
        $this->getJson($this->urlFor($analysis, '/report'))->assertOk()->assertJsonPath('data.translation.complete', true)
            ->assertJsonPath('data.claims.0.statement', 'North accounted for 54.3% of Revenue across Region.');
        // The claim endpoint and the PDF follow the interface language too
        $this->getJson($this->urlFor($analysis, '/claims/'.$c1->public_id), ['X-Dataspeak-Language' => 'th'])->assertOk()->assertJsonPath('data.language', 'th')->assertJsonPath('data.statement', 'ภาคเหนือ คิดเป็น 54.3% ของ ยอดขาย เมื่อแบ่งตาม ภูมิภาค');
        $pdf = $this->get($this->urlFor($analysis, '/report/pdf'), ['X-Dataspeak-Language' => 'th']);
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        // A second call translates only what is still pending, and a model outage is reported, not thrown
        $this->app->instance(LlmClient::class, new FakeLlmClient(null, down: true));
        $this->postJson($this->urlFor($analysis, '/report/translate'), ['language' => 'th'])->assertOk()->assertJsonPath('data.ai_status', 'unavailable')->assertJsonPath('data.pending', 1);
        $this->postJson($this->urlFor($analysis, '/report/translate'), ['language' => 'en'])->assertOk()->assertJsonPath('data.ai_status', 'not_needed');
    }

    public function test_reports_are_pretranslated_in_the_background_and_the_page_is_told_to_wait(): void
    {
        config(['dataspeak.ai.pretranslate' => true]);
        Queue::fake([TranslateReportJob::class]);
        $analysis = $this->englishReport();

        Queue::assertPushed(TranslateReportJob::class, 3);
        Queue::assertPushed(TranslateReportJob::class, fn ($job) => $job->locale === 'th');
        Queue::assertPushed(TranslateReportJob::class, fn ($job) => $job->queue === TranslateReportJob::QUEUE);
        Queue::assertNotPushed(TranslateReportJob::class, fn ($job) => $job->locale === 'en');
        $this->assertTrue(TranslateReportJob::inProgress($analysis, 'th'));
        $this->getJson($this->urlFor($analysis, '/report'), ['X-Dataspeak-Language' => 'th'])->assertOk()->assertJsonPath('data.translation.in_progress', true);
        $this->postJson($this->urlFor($analysis, '/report/translate'), ['language' => 'th'])->assertStatus(202)->assertJsonPath('data.status', 'in_progress');

        // The worker runs the job: labels and statements for Thai, flag cleared, switch is instant afterwards
        $share = AiScenario::EV_SHARE;
        $c1 = $analysis->claims()->where('in_report', true)->orderBy('position')->first();
        $this->app->instance(LlmClient::class, new FakeLlmClient(['claims' => []], false, [
            ['labels' => [['original' => 'Revenue', 'english' => 'ยอดขาย'], ['original' => 'Region', 'english' => 'ภูมิภาค'], ['original' => 'North', 'english' => 'ภาคเหนือ'], ['original' => 'Order Date', 'english' => 'วันที่สั่งซื้อ']]],
            ['claims' => [['id' => $c1->public_id, 'text' => '{'.$share.'.group} คิดเป็น {'.$share.'.display_value} ของ {'.$share.'.label} เมื่อแบ่งตาม {'.$share.'.dimension}']]],
        ]));
        (new TranslateReportJob($analysis->id, 'th'))->handle(app(DisplayLabelTranslator::class), app(ClaimTranslator::class));

        $this->assertFalse(TranslateReportJob::inProgress($analysis, 'th'));
        $this->getJson($this->urlFor($analysis, '/report'), ['X-Dataspeak-Language' => 'th'])->assertOk()
            ->assertJsonPath('data.translation.in_progress', false)
            ->assertJsonPath('data.claims.0.statement', 'ภาคเหนือ คิดเป็น 54.3% ของ ยอดขาย เมื่อแบ่งตาม ภูมิภาค');
    }

    public function test_sentences_the_model_skips_in_a_batch_get_a_strict_second_pass_on_their_own(): void
    {
        $analysis = $this->englishReport();
        $share = AiScenario::EV_SHARE;
        $growth = AiScenario::EV_GROWTH;
        $claims = $analysis->claims()->where('in_report', true)->orderBy('position')->get();
        $c1 = $claims->firstWhere('claim_kind', 'EXECUTIVE_SUMMARY');
        $c2 = $claims->first(fn ($c) => str_contains($c->statement, $growth));
        $this->app->instance(LlmClient::class, $llm = new FakeLlmClient(['claims' => []], false, [
            ['labels' => []],
            // first pass: the model answers only one of the two sentences (near-identical ones get merged)
            ['claims' => [['id' => $c1->public_id, 'text' => '{'.$share.'.group} คิดเป็น {'.$share.'.display_value} ของ {'.$share.'.label} เมื่อแบ่งตาม {'.$share.'.dimension}']]],
            // strict pass: the missing sentence alone
            ['claims' => [['id' => $c2->public_id, 'text' => '{'.$growth.'.label} เติบโต {'.$growth.'.display_value} ต่อ {'.$growth.'.period} โดยเฉลี่ย']]],
        ]));

        $this->postJson($this->urlFor($analysis, '/report/translate'), ['language' => 'th'])->assertOk()
            ->assertJsonPath('data.translated', 2)->assertJsonPath('data.pending', 0)->assertJsonPath('data.ai_status', 'ready');
        $this->assertSame(3, $llm->calls);
        $this->assertStringContainsString('must contain exactly these placeholders', $llm->lastMessages[0]['content']);
        $this->assertStringContainsString('{'.$growth.'.display_value}', $llm->lastMessages[0]['content']);
    }

    public function test_acceptance_rules(): void
    {
        $t = app(ClaimTranslator::class);
        $o = '{ev_aaaaaaaaaaa3.group} accounted for {ev_aaaaaaaaaaa3.display_value} of {ev_aaaaaaaaaaa3.label}.';
        $this->assertTrue($t->acceptable($o, '{ev_aaaaaaaaaaa3.group} 占 {ev_aaaaaaaaaaa3.label} 的 {ev_aaaaaaaaaaa3.display_value}。', 'zh'));
        $this->assertFalse($t->acceptable($o, '{ev_aaaaaaaaaaa3.group} 占 {ev_aaaaaaaaaaa3.label} 的 54%。', 'zh'));                       // placeholder lost
        $this->assertFalse($t->acceptable($o, '{ev_aaaaaaaaaaa3.group} accounted for {ev_aaaaaaaaaaa3.display_value} of {ev_aaaaaaaaaaa3.label}.', 'zh')); // not translated
        $this->assertFalse($t->acceptable($o, '{ev_aaaaaaaaaaa3.group} 占 {ev_aaaaaaaaaaa3.label} 的 {ev_aaaaaaaaaaa3.display_value} {ev_aaaaaaaaaaa3.unit}。', 'zh')); // extra placeholder
        $this->assertFalse($t->acceptable($o, '', 'zh'));

        // A Chinese full stop carried over into a Thai sentence is normalised away instead of rejecting the sentence
        $thai = '{ev_aaaaaaaaaaa3.group} คิดเป็น {ev_aaaaaaaaaaa3.display_value} ของ {ev_aaaaaaaaaaa3.label}。';
        $this->assertFalse($t->acceptable($o, $thai, 'th'));
        $this->assertSame('{ev_aaaaaaaaaaa3.group} คิดเป็น {ev_aaaaaaaaaaa3.display_value} ของ {ev_aaaaaaaaaaa3.label}.', ClaimTranslator::normalizeText($thai, 'th'));
        $this->assertTrue($t->acceptable($o, ClaimTranslator::normalizeText($thai, 'th'), 'th'));
        $this->assertSame('{ev_aaaaaaaaaaa3.group} 占 {ev_aaaaaaaaaaa3.label}。', ClaimTranslator::normalizeText(" {ev_aaaaaaaaaaa3.group} 占  {ev_aaaaaaaaaaa3.label}。\n", 'zh'));
        $this->assertSame(4, ClaimTranslator::batchSize('en', 'my'));
        $this->assertSame(8, ClaimTranslator::batchSize('en', 'th'));
        $this->assertSame(2, ClaimTranslator::batchSize('en', 'th', 2));
        $this->assertSame(0.0, ClaimTranslator::options(4)['temperature']);
        $this->assertSame(1600, ClaimTranslator::options(4)['num_predict']);
        $this->assertSame(0.3, ClaimTranslator::options(4, 2)['temperature']);
    }

    public function test_background_translation_retries_with_a_delay_when_the_model_is_unavailable(): void
    {
        $analysis = $this->englishReport();
        $analysis->forceFill(['label_translations' => DisplayLabels::normalizeStored($analysis->label_translations) + ['my' => []]])->save();
        $this->app->instance(LlmClient::class, new FakeLlmClient(null, down: true));
        Queue::fake([TranslateReportJob::class]);

        (new TranslateReportJob($analysis->id, 'my'))->handle(app(DisplayLabelTranslator::class), app(ClaimTranslator::class));
        Queue::assertPushed(TranslateReportJob::class, fn ($job) => $job->attempt === 2 && $job->locale === 'my' && $job->queue === TranslateReportJob::QUEUE && $job->delay !== null);
        $this->assertTrue(TranslateReportJob::inProgress($analysis, 'my'), 'the page keeps polling while the retry is queued');

        // The last attempt gives up quietly: flag cleared, no further job, originals keep being shown
        (new TranslateReportJob($analysis->id, 'my', TranslateReportJob::MAX_ATTEMPTS))->handle(app(DisplayLabelTranslator::class), app(ClaimTranslator::class));
        Queue::assertPushed(TranslateReportJob::class, 1);
        $this->assertFalse(TranslateReportJob::inProgress($analysis, 'my'));
        $this->getJson($this->urlFor($analysis, '/report'), ['X-Dataspeak-Language' => 'my'])->assertOk()->assertJsonPath('data.translation.in_progress', false)->assertJsonPath('data.translation.complete', false);
    }

    public function test_requires_a_completed_report_and_a_supported_language(): void
    {
        $analysis = AiScenario::build($this->actingAsUser());
        $analysis->forceFill(['status' => AnalysisStatus::ANALYZED])->save();
        $this->postJson($this->urlFor($analysis, '/report/translate'), ['language' => 'th'])->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');
        $completed = $this->englishReport();
        $this->postJson($this->urlFor($completed, '/report/translate'), ['language' => 'xx'])->assertStatus(422);
        $this->assertSame(0, Claim::query()->whereNotNull('statement_translations')->count());
    }

    public function test_pdf_export_follows_the_interface_language(): void
    {
        $analysis = $this->englishReport();
        $share = AiScenario::EV_SHARE;
        $analysis->forceFill(['label_translations' => DisplayLabels::normalizeStored($analysis->label_translations) + ['th' => ['Revenue' => 'ยอดขาย', 'Region' => 'ภูมิภาค', 'North' => 'ภาคเหนือ']]])->save();
        foreach ($analysis->claims()->where('in_report', true)->get() as $claim) {
            $claim->forceFill(['statement_translations' => ['th' => '{'.$share.'.group} คิดเป็น {'.$share.'.display_value} ของ {'.$share.'.label}']])->save();
        }

        $thai = $this->get($this->urlFor($analysis, '/report/pdf'), ['Accept' => 'application/json', 'X-Dataspeak-Language' => 'th']);
        $thai->assertOk()->assertHeader('Content-Language', 'th');
        $this->assertStringContainsString('NotoSansThai', $thai->getContent(), 'Thai glyphs are typeset with the Thai font');

        $english = $this->get($this->urlFor($analysis, '/report/pdf'), ['Accept' => 'application/json']);
        $english->assertOk()->assertHeader('Content-Language', 'en');
        $this->assertStringNotContainsString('NotoSansThai', $english->getContent());
    }

    public function test_pdf_draws_long_or_non_latin_bar_labels_as_html_bars(): void
    {
        $thai = ['type' => 'BAR', 'x' => ['field' => 'group', 'label' => 'รอบประมูล'], 'y' => ['field' => 'mean', 'label' => 'ระยะทาง', 'unit' => 'km'],
            'series' => [['group' => 'รายการรถยนต์ประมูลวันอาทิตย์', 'mean' => 232134], ['group' => 'รายการประมูลรถจักรยานยนต์', 'mean' => 59451]]];
        $this->assertTrue(ChartHtml::wantsHtml($thai));
        $html = ChartHtml::bars($thai);
        $this->assertSame(2, substr_count($html, '<tr>'));
        $this->assertStringContainsString('<span class="th">รายการรถยนต์ประมูลวันอาทิตย์</span>', $html);
        $this->assertStringContainsString('width:100%', $html);
        $this->assertStringContainsString('width:26%', $html);
        $this->assertStringContainsString('232,134', $html);
        $this->assertFalse(ChartHtml::wantsHtml(['type' => 'BAR', 'x' => ['field' => 'g'], 'y' => ['field' => 'v'], 'series' => [['g' => 'North', 'v' => 1]]]));
        $this->assertFalse(ChartHtml::wantsHtml(['type' => 'LINE', 'x' => ['field' => 'p'], 'y' => ['field' => 'v'], 'series' => [['p' => 'รายการรถยนต์ประมูลวันอาทิตย์', 'v' => 1]]]));
    }
}
