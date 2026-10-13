<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisFamily;
use App\Enums\AnalysisStatus;
use App\Enums\QualityStatus;
use App\Models\Analysis;
use App\Models\Evidence;
use App\Services\Reports\ChartCatalog;
use App\Services\Reports\ChartGrounding;
use App\Services\Reports\Pdf\ChartSvg;
use App\Services\Reports\ReportAssembler;
use App\Support\ChartThemes;
use Tests\Support\AiScenario;
use Tests\Support\ApiTestCase;

/** FEAT-VIS-003 / VIS-API-002, VIS-API-003: review the recommended charts, remove some, add others, then report. */
class ChartSelectionTest extends ApiTestCase
{
    private function analysed(): Analysis
    {
        $analysis = AiScenario::build($this->actingAsUser(), revenueLabel: 'Revenue', regionLabel: 'Region');
        $analysis->forceFill(['status' => AnalysisStatus::ANALYZED])->save();
        // Evidence the recommender did not chart: a group aggregate (bar sum / bar mean) and a monthly series (line)
        $analysis->evidence()->create(['evidence_key' => 'ev_bbbbbbbbbbb1', 'analysis_family' => AnalysisFamily::GROUP_COMPARISON, 'metric' => 'group_aggregates', 'method' => 'group_by_aggregate',
            'value' => null, 'display_value' => null, 'source_columns' => ['region', 'revenue_thb'], 'quality_status' => QualityStatus::ACCEPTABLE, 'parameters' => ['aggregation' => 'sum'],
            'payload' => ['unit' => 'THB', 'groups' => [['group' => 'North', 'sum' => 7600000, 'mean' => 420.5, 'count' => 130], ['group' => 'South', 'sum' => 6400000, 'mean' => 380.2, 'count' => 110]]]]);
        $analysis->evidence()->create(['evidence_key' => 'ev_bbbbbbbbbbb2', 'analysis_family' => AnalysisFamily::TREND, 'metric' => 'period_series', 'method' => 'period_aggregation_sum',
            'value' => null, 'display_value' => null, 'source_columns' => ['order_date', 'revenue_thb'], 'quality_status' => QualityStatus::ACCEPTABLE, 'parameters' => ['period' => 'month'],
            'payload' => ['unit' => 'THB', 'series' => [['period' => '2025-01', 'value' => 1000], ['period' => '2025-02', 'value' => 1200]]]]);

        return $analysis;
    }

    public function test_options_list_every_supportable_chart_not_yet_present(): void
    {
        $analysis = $this->analysed();

        $response = $this->getJson($this->urlFor($analysis, '/visualizations/options'))->assertOk();
        $keys = collect($response->json('data'))->pluck('key')->all();

        $this->assertContains('bar_sum:ev_bbbbbbbbbbb1', $keys);
        $this->assertContains('bar_mean:ev_bbbbbbbbbbb1', $keys);
        $this->assertContains('line:ev_bbbbbbbbbbb2', $keys);
        $this->assertContains('missing', $keys); // the scenario has a null_rate finding
        // FEAT-VIS-005: a share-of-total pie for the group aggregate, drawn from the stored sums
        $this->assertContains('pie:ev_bbbbbbbbbbb1', $keys);
        $pie = collect($response->json('data'))->firstWhere('key', 'pie:ev_bbbbbbbbbbb1');
        $this->assertSame(['PIE', 'Share of Revenue by Region', 'sum'], [$pie['type'], $pie['title'], $pie['y']['field']]);
        $this->assertSame([['group' => 'North', 'sum' => 7600000, 'count' => 130], ['group' => 'South', 'sum' => 6400000, 'count' => 110]], $pie['series']);
        $line = collect($response->json('data'))->firstWhere('key', 'line:ev_bbbbbbbbbbb2');
        $this->assertSame(['type' => 'LINE', 'title' => 'Revenue by month', 'unit' => 'THB', 'points' => 2], ['type' => $line['type'], 'title' => $line['title'], 'unit' => $line['unit'], 'points' => $line['points']]);
        $this->assertSame([['period' => '2025-01', 'value' => 1000], ['period' => '2025-02', 'value' => 1200]], $line['series']); // data for the preview
        $this->assertSame('value', $line['y']['field']);
        // FEAT-VIS-006: every option and every saved chart explains itself from the definitions, in the interface language
        $this->assertSame('Revenue was defined as a measure recorded over time, so a line shows how it moved from period to period.', $line['reason']);
        $mean = collect($response->json('data'))->firstWhere('key', 'bar_mean:ev_bbbbbbbbbbb1');
        $this->assertStringContainsString('property of each record', $mean['reason']);
        $this->assertStringContainsString('ถูกกำหนด', $this->getJson($this->urlFor($analysis, '/visualizations'), ['X-Dataspeak-Language' => 'th'])->assertOk()->json('data.0.reason'));
        $this->getJson($this->urlFor($analysis, '/visualizations'))->assertOk()->assertJsonPath('data.0.source', 'RECOMMENDED')->assertJsonPath('data.0.is_selected', true);
    }

    public function test_selection_removes_recommended_charts_adds_chosen_ones_and_drives_the_report(): void
    {
        $analysis = $this->analysed();
        $recommended = (string) $analysis->visualizations()->first()->id;

        $response = $this->putJson($this->urlFor($analysis, '/visualizations/selection'), ['selected' => [], 'add' => ['bar_mean:ev_bbbbbbbbbbb1', 'line:ev_bbbbbbbbbbb2']]);

        $response->assertOk();
        $charts = collect($response->json('data'));
        $this->assertSame(3, $charts->count());
        $this->assertFalse($charts->firstWhere('id', $recommended)['is_selected']);
        $added = $charts->firstWhere('title', 'Average Revenue by Region');
        $this->assertSame('USER', $added['source']);
        $this->assertTrue($added['is_selected']);
        $this->assertSame([['group' => 'North', 'mean' => 420.5, 'count' => 130], ['group' => 'South', 'mean' => 380.2, 'count' => 110]], $added['series']);
        $this->assertSame('mean', $added['y']['field']);
        $this->assertSame(['ev_bbbbbbbbbbb1'], $added['evidence_ids']);

        // The report, the chart grounding for the narrative and the options list all follow the kept set
        app(ReportAssembler::class)->assemble($analysis->fresh(), null, 'unavailable');
        $analysis->forceFill(['status' => AnalysisStatus::COMPLETED])->save();
        $titles = collect($this->getJson($this->urlFor($analysis, '/report'))->assertOk()->json('data.visualizations'))->pluck('title')->all();
        $this->assertSame(['Average Revenue by Region', 'Revenue by month'], $titles);
        // The narrative grounding only knows the kept charts: the deselected recommended chart's position is gone.
        $grounding = ChartGrounding::for($analysis->fresh());
        $recommendedPosition = (int) $analysis->visualizations()->first()->position;
        $this->assertNotContains($recommendedPosition, $grounding->chartsFor((array) $analysis->visualizations()->first()->evidence_keys));
        $this->assertNotNull($grounding->chartFor(['ev_bbbbbbbbbbb2']));
        $this->assertNotContains('line:ev_bbbbbbbbbbb2', collect($this->getJson($this->urlFor($analysis, '/visualizations/options'))->json('data'))->pluck('key')->all());
    }

    public function test_colour_theme_is_chosen_at_chart_review_and_follows_the_report_and_pdf(): void
    {
        $analysis = $this->analysed();
        $kept = (string) $analysis->visualizations()->first()->id;
        $this->getJson($this->urlFor($analysis, ''))->assertOk()->assertJsonPath('data.chart_theme', 'emerald');

        $this->putJson($this->urlFor($analysis, '/visualizations/selection'), ['selected' => [$kept], 'theme' => 'ocean'])->assertOk();
        $this->assertSame('ocean', $analysis->fresh()->chart_theme);
        $this->getJson($this->urlFor($analysis, ''))->assertOk()->assertJsonPath('data.chart_theme', 'ocean');

        app(ReportAssembler::class)->assemble($analysis->fresh(), null, 'unavailable');
        $analysis->forceFill(['status' => AnalysisStatus::COMPLETED])->save();
        $this->getJson($this->urlFor($analysis, '/report'))->assertOk()->assertJsonPath('data.chart_theme', 'ocean');
        $this->get($this->urlFor($analysis, '/report/pdf'), ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(ChartThemes::colors('ocean'), ChartSvg::$palette, 'the PDF charts were drawn with the chosen theme');

        $this->putJson($this->urlFor($analysis, '/visualizations/selection'), ['selected' => [$kept], 'theme' => 'neon'])->assertStatus(422);
        $this->assertSame('ocean', $analysis->fresh()->chart_theme);
    }

    public function test_selection_guards(): void
    {
        $analysis = $this->analysed();

        $this->putJson($this->urlFor($analysis, '/visualizations/selection'), ['selected' => []])->assertStatus(422)->assertJsonPath('error.code', 'NO_CHARTS_SELECTED');
        $this->putJson($this->urlFor($analysis, '/visualizations/selection'), ['selected' => [], 'add' => ['bar_sum:ev_nope']])->assertStatus(422)->assertJsonPath('error.code', 'UNKNOWN_CHART_OPTION');
        $this->assertSame(1, $analysis->visualizations()->count());

        $analysis->forceFill(['status' => AnalysisStatus::DEFINED])->save();
        $this->putJson($this->urlFor($analysis, '/visualizations/selection'), ['selected' => []])->assertStatus(409);
        $this->assertNotEmpty(app(ChartCatalog::class)->all($analysis)); // the catalog itself never writes
    }
}
