<?php

namespace Tests\Unit;

use App\Services\Reports\Pdf\ChartSvg;
use App\Services\Reports\Pdf\ScriptSpans;
use PHPUnit\Framework\TestCase;

class ChartSvgTest extends TestCase
{
    public function test_bar_chart_draws_one_rect_per_point_with_labels(): void
    {
        $svg = ChartSvg::render(['type' => 'BAR', 'x' => ['field' => 'group', 'label' => 'Region'], 'y' => ['field' => 'sum', 'label' => 'Revenue', 'unit' => 'THB'],
            'series' => [['group' => 'North', 'sum' => 7600000], ['group' => 'South', 'sum' => 6400000], ['group' => 'East', 'sum' => 100]]]);
        $this->assertNotNull($svg);
        $this->assertSame(3, substr_count($svg, '<rect'));
        $this->assertStringContainsString('North', $svg);
        $this->assertStringContainsString('Revenue (THB)', $svg);
        $this->assertStringContainsString('7.6M', $svg);
    }

    public function test_bars_cycle_through_the_colour_theme_and_lines_use_its_first_colour(): void
    {
        $previous = ChartSvg::$palette;
        ChartSvg::$palette = ['#111111', '#222222'];
        try {
            $bars = ChartSvg::render(['type' => 'BAR', 'x' => ['field' => 'group', 'label' => 'Region'], 'y' => ['field' => 'sum', 'label' => 'Revenue'],
                'series' => [['group' => 'North', 'sum' => 3], ['group' => 'South', 'sum' => 2], ['group' => 'East', 'sum' => 1]]]);
            $this->assertSame(2, substr_count($bars, 'fill="#111111"'));
            $this->assertSame(1, substr_count($bars, 'fill="#222222"'));
            $line = ChartSvg::render(['type' => 'LINE', 'x' => ['field' => 'period', 'label' => 'Month'], 'y' => ['field' => 'sum', 'label' => 'Revenue'],
                'series' => [['period' => '2025-01', 'sum' => 3], ['period' => '2025-02', 'sum' => 2]]]);
            $this->assertStringContainsString('stroke="#111111"', $line);
            $this->assertStringNotContainsString('#222222', $line);
        } finally {
            ChartSvg::$palette = $previous;
        }
    }

    public function test_pie_draws_one_slice_per_group_with_shares_and_a_legend(): void
    {
        $svg = ChartSvg::render(['type' => 'PIE', 'x' => ['field' => 'group', 'label' => 'Region'], 'y' => ['field' => 'sum', 'label' => 'Revenue', 'unit' => 'THB'],
            'series' => [['group' => 'North', 'sum' => 50], ['group' => 'South', 'sum' => 30], ['group' => 'East', 'sum' => 20]]]);
        $this->assertNotNull($svg);
        $this->assertSame(3, substr_count($svg, '<path '));
        $this->assertStringContainsString('50%', $svg);
        $this->assertStringContainsString('30%', $svg);
        $this->assertStringContainsString('North', $svg);
        $this->assertStringContainsString('Revenue (THB)', $svg);
        $single = ChartSvg::render(['type' => 'PIE', 'x' => ['field' => 'group'], 'y' => ['field' => 'sum'], 'series' => [['group' => 'All', 'sum' => 9]]]);
        $this->assertStringContainsString('<circle', $single);
    }

    public function test_non_latin_labels_become_a_numbered_key_outside_the_svg(): void
    {
        $drawn = ChartSvg::renderWithKey(['type' => 'BAR', 'x' => ['field' => 'group', 'label' => 'ဒေသ'], 'y' => ['field' => 'sum', 'label' => 'ยอดขาย', 'unit' => 'THB'],
            'series' => [['group' => 'ภาคเหนือ', 'sum' => 10], ['group' => 'ภาคใต้', 'sum' => 20]]]);
        $this->assertSame([['n' => 1, 'label' => 'ภาคเหนือ'], ['n' => 2, 'label' => 'ภาคใต้']], $drawn['key']);
        $this->assertStringNotContainsString('ภาค', $drawn['svg']);
        $this->assertStringNotContainsString('ยอดขาย', $drawn['svg']);
        $this->assertStringContainsString('(THB)', $drawn['svg']);
    }

    public function test_line_and_histogram_and_unsupported(): void
    {
        $line = ChartSvg::render(['type' => 'LINE', 'x' => ['field' => 'period', 'label' => 'Month'], 'y' => ['field' => 'value', 'label' => 'Revenue'],
            'series' => array_map(fn ($i) => ['period' => sprintf('2025-%02d', $i), 'value' => 1000 + $i * 50], range(1, 12))]);
        $this->assertStringContainsString('<polyline', $line);
        $hist = ChartSvg::render(['type' => 'HISTOGRAM', 'x' => ['field' => 'from', 'label' => 'Revenue'], 'y' => ['field' => 'count', 'label' => 'Rows'],
            'series' => [['from' => 0, 'to' => 100, 'count' => 5], ['from' => 100, 'to' => 200, 'count' => 9]]]);
        $this->assertSame(2, substr_count($hist, '<rect'));
        $this->assertStringContainsString('>0<', $hist);
        $this->assertStringContainsString('>100<', $hist);
        $this->assertSame('9.0k', ChartSvg::compact(9000));
        $this->assertSame('8,376', ChartSvg::tick(8376.04));
        $this->assertNull(ChartSvg::render(['type' => 'TABLE', 'x' => ['field' => 'statistic'], 'y' => ['field' => 'value'], 'series' => [['statistic' => 'rho', 'value' => 0.7]]]));
        $this->assertNull(ChartSvg::render(['type' => 'BAR', 'x' => ['field' => 'g'], 'y' => ['field' => 'v'], 'series' => []]));
    }

    public function test_long_labels_sharing_a_prefix_are_shown_by_their_tail(): void
    {
        $labels = [
            'Car Auction List Sunday, October 4, 2026, Rangsit Branch (Image) (Lot 3) Start Time 12:00:00',
            'Car Auction List Sunday, October 4, 2026, Rangsit Branch (Lot 1 Truck) (10.30 am) Start Time 10:30:00',
            'Car Auction List Sunday, October 4, 2026, Rangsit Branch (Lot 3) (Continuing) Start Time 12:00:00',
        ];
        $this->assertSame(['…Image) (Lot 3) Start Time 12:00:00', '…Lot 1 Truck) (10.30 am) Start Time 10:30:00', '…Lot 3) (Continuing) Start Time 12:00:00'], ChartSvg::distinguish($labels));
        $this->assertSame(['North', 'South'], ChartSvg::distinguish(['North', 'South']));
        $this->assertSame(['Northern Region Office', 'Southern Region Office'], ChartSvg::distinguish(['Northern Region Office', 'Southern Region Office']));
    }

    public function test_long_latin_labels_are_numbered_with_a_key(): void
    {
        $drawn = ChartSvg::renderWithKey(['type' => 'BAR', 'x' => ['field' => 'round', 'label' => 'Auction Round'], 'y' => ['field' => 'mean', 'label' => 'CC'],
            'series' => [['round' => 'Car Auction List Sunday (Lot 3)', 'mean' => 2156], ['round' => 'Motorcycle Auction List Sunday (Lot 3)', 'mean' => 150]]]);
        $this->assertSame([['n' => 1, 'label' => 'Car Auction List Sunday (Lot 3)'], ['n' => 2, 'label' => 'Motorcycle Auction List Sunday (Lot 3)']], $drawn['key']);
        $this->assertStringContainsString('>1<', $drawn['svg']);
        $this->assertSame([], ChartSvg::renderWithKey(['type' => 'BAR', 'x' => ['field' => 'g'], 'y' => ['field' => 'v'], 'series' => [['g' => 'North', 'v' => 1], ['g' => 'South', 'v' => 2]]])['key']);
    }

    public function test_script_spans_wrap_thai_and_myanmar_and_escape_html(): void
    {
        $html = ScriptSpans::html('North <b> ยอดขาย across ဒေသ.');
        $this->assertSame('North &lt;b&gt; <span class="th">ยอดขาย</span> across <span class="my">ဒေသ</span>.', $html);
    }
}
