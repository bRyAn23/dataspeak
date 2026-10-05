<?php

namespace App\Services\Reports;

use App\Models\Analysis;
use App\Models\Visualization;

/**
 * Links evidence (and therefore claims) to the report's charts by the columns they describe, and ranks
 * which evidence metric best explains a given chart. Shared by claim placement and report assembly.
 */
class ChartGrounding
{
    /** Metrics that may lead a chart's reading, by what the chart shows. */
    public const PREFERENCE = [
        'line' => ['average_period_growth', 'total_change', 'latest_period_change', 'trend_slope'],
        'bar_sum' => ['top_group_share', 'top_category_share', 'top_bottom_mean_difference'],
        'bar_mean' => ['top_bottom_mean_difference', 'top_group_share', 'group_difference_test'],
        'histogram' => ['mean', 'median', 'sum', 'min', 'max'],
        'table' => ['spearman_correlation', 'cramers_v'],
        'default' => ['top_group_share', 'top_bottom_mean_difference', 'average_period_growth', 'total_change', 'latest_period_change', 'spearman_correlation', 'cramers_v', 'top_category_share', 'sum', 'mean', 'median'],
    ];

    /** Metrics that never lead an executive summary sentence. */
    public const NOT_FOR_SUMMARY = ['group_difference_test', 'row_count', 'count', 'completeness', 'duplicate_rows', 'null_rate', 'outlier_count', 'constant_column', 'type_parse_failures', 'distinct_count', 'distribution', 'period_series', 'group_aggregates', 'std', 'min', 'max'];

    /** @var array<string, list<int>> column signature => chart positions sharing it */
    private array $chartsBySignature = [];

    /** @var array<string, string> evidence key => signature */
    private array $signatureByKey = [];

    /** @var array<string, string> evidence key => metric */
    private array $metricByKey = [];

    /** @var array<int, Visualization> */
    private array $charts = [];

    public static function for(Analysis $analysis): self
    {
        $analysis->loadMissing(['selectedVisualizations', 'evidence']);
        $self = new self;
        foreach ($analysis->evidence as $e) {
            $self->signatureByKey[$e->evidence_key] = self::signature($e->source_columns);
            $self->metricByKey[$e->evidence_key] = $e->metric;
        }
        foreach ($analysis->selectedVisualizations->sortBy('position') as $chart) {
            $self->charts[(int) $chart->position] = $chart;
            foreach ((array) $chart->evidence_keys as $key) {
                $sig = $self->signatureByKey[$key] ?? null;
                if ($sig !== null && $sig !== '' && ! in_array((int) $chart->position, $self->chartsBySignature[$sig] ?? [], true)) {
                    $self->chartsBySignature[$sig][] = (int) $chart->position;
                }
            }
        }

        return $self;
    }

    /** Primary chart (lowest position) for the evidence keys, or null when none is charted. */
    public function chartFor(array $evidenceKeys): ?int
    {
        $positions = [];
        foreach ($evidenceKeys as $k) {
            foreach ($this->chartsBySignature[$this->signatureByKey[$k] ?? ''] ?? [] as $p) {
                $positions[] = $p;
            }
        }

        return $positions === [] ? null : min($positions);
    }

    /** Every chart whose columns the evidence keys describe. @return list<int> */
    public function chartsFor(array $evidenceKeys): array
    {
        $positions = [];
        foreach ($evidenceKeys as $k) {
            foreach ($this->chartsBySignature[$this->signatureByKey[$k] ?? ''] ?? [] as $p) {
                $positions[$p] = true;
            }
        }
        ksort($positions);

        return array_keys($positions);
    }

    /** Evidence keys describing the same columns as the chart, best-explaining metrics first. @return list<string> */
    public function evidenceKeysFor(int $chartPosition): array
    {
        $keys = [];
        foreach ($this->signatureByKey as $key => $sig) {
            if (in_array($chartPosition, $this->chartsBySignature[$sig] ?? [], true)) {
                $keys[] = $key;
            }
        }
        usort($keys, fn ($a, $b) => $this->metricRank($chartPosition, $this->metricByKey[$a] ?? '') <=> $this->metricRank($chartPosition, $this->metricByKey[$b] ?? ''));

        return $keys;
    }

    /** Lower is better. Metrics outside the chart's preference list rank after it; NOT_FOR_SUMMARY last. */
    public function metricRank(int $chartPosition, string $metric): int
    {
        $prefs = self::PREFERENCE[$this->chartKind($chartPosition)] ?? self::PREFERENCE['default'];
        $i = array_search($metric, $prefs, true);
        if ($i !== false) {
            return $i;
        }
        $j = array_search($metric, self::PREFERENCE['default'], true);

        return $j !== false ? 100 + $j : (in_array($metric, self::NOT_FOR_SUMMARY, true) ? 900 : 500);
    }

    /** Best rank of a claim's metrics for the chart it is grounded in (used to pick the summary sentence per chart). */
    public function claimRank(int $chartPosition, array $metrics): int
    {
        $ranks = array_map(fn ($m) => $this->metricRank($chartPosition, (string) $m), $metrics);

        return $ranks === [] ? 999 : min($ranks);
    }

    public function chartKind(int $chartPosition): string
    {
        $chart = $this->charts[$chartPosition] ?? null;
        if ($chart === null) {
            return 'default';
        }
        $type = strtolower($chart->chart_type->value);
        if ($type === 'bar') {
            $field = (string) ($chart->spec['y']['field'] ?? '');

            return $field === 'mean' ? 'bar_mean' : 'bar_sum';
        }

        return in_array($type, ['line', 'histogram', 'table'], true) ? $type : 'default';
    }

    public static function signature(?array $columns): string
    {
        $cols = array_values(array_unique(array_map('strval', $columns ?? [])));
        sort($cols);

        return implode('|', $cols);
    }
}
