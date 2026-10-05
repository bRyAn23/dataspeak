<?php

namespace App\Services\Reports;

use App\Enums\QualityStatus;
use App\Models\Analysis;
use App\Models\Evidence;

/**
 * FEAT-VIS-003 chart options. Mirrors the analytics recommender (PY-API-006): every chart is a specification
 * over stored evidence payloads (period series, group aggregates, histogram bins, correlation statistics), so a
 * chart the user adds is as calculated as a recommended one. Nothing here reads dataset rows.
 */
class ChartCatalog
{
    /** @return list<array<string, mixed>> every supportable chart, keyed, in evidence order */
    public function all(Analysis $analysis): array
    {
        $analysis->loadMissing(['columns.definition', 'evidence', 'insights.evidence']);
        $labels = $analysis->columns->mapWithKeys(fn ($c) => [$c->source_name => $c->definition?->business_name ?: $c->source_name])->all();
        $units = $analysis->columns->mapWithKeys(fn ($c) => [$c->source_name => $c->definition?->unit])->all();
        $aggregations = $analysis->columns->mapWithKeys(fn ($c) => [$c->source_name => $c->definition?->aggregation?->value])->all();
        // A pie shows parts of a whole, so only additive measures qualify (SUM/COUNT, or undefined and not a percentage).
        $additive = fn (?string $c, ?string $unit) => in_array($aggregations[$c] ?? null, ['SUM', 'COUNT'], true) || (($aggregations[$c] ?? null) === null && $unit !== '%');
        $lab = fn (?string $c) => $c === null ? '' : ($labels[$c] ?? $c);
        $unitFor = fn (?string $c, ?string $payloadUnit) => ($c !== null && ! empty($units[$c])) ? $units[$c] : $payloadUnit;
        $rankFor = function (array $cols) use ($analysis): ?int {
            $ranks = $analysis->insights->filter(fn ($i) => $i->evidence !== null && ($i->evidence->source_columns ?? []) === $cols)->pluck('rank');

            return $ranks->isEmpty() ? null : (int) $ranks->min();
        };

        $options = [];
        $missing = [];
        foreach ($analysis->evidence->sortBy('id') as $e) {
            if ($e->quality_status === QualityStatus::INSUFFICIENT) {
                continue;
            }
            $cols = (array) ($e->source_columns ?? []);
            $p = (array) ($e->payload ?? []);
            $params = (array) ($e->parameters ?? []);
            switch ($e->metric) {
                case 'period_series':
                    [$time, $measure] = [$cols[0] ?? null, $cols[1] ?? null];
                    $unit = $unitFor($measure, $p['unit'] ?? null);
                    $options[] = $this->item('line:'.$e->evidence_key, 'LINE', $lab($measure).' by '.($params['period'] ?? 'period'), $cols, $e, $rankFor($cols),
                        ['field' => 'period', 'label' => $lab($time), 'unit' => null], ['field' => 'value', 'label' => $lab($measure), 'unit' => $unit],
                        array_map(fn ($pt) => ['period' => $pt['period'] ?? null, 'value' => $pt['value'] ?? null], (array) ($p['series'] ?? [])));
                    break;
                case 'group_aggregates':
                    [$dim, $measure] = [$cols[0] ?? null, $cols[1] ?? null];
                    $unit = $unitFor($measure, $p['unit'] ?? null);
                    foreach (['sum' => 'Total', 'mean' => 'Average'] as $field => $word) {
                        $options[] = $this->item("bar_{$field}:".$e->evidence_key, 'BAR', "{$word} ".$lab($measure).' by '.$lab($dim), $cols, $e, $rankFor($cols),
                            ['field' => 'group', 'label' => $lab($dim), 'unit' => null], ['field' => $field, 'label' => $lab($measure), 'unit' => $unit],
                            array_map(fn ($g) => ['group' => $g['group'] ?? null, $field => $g[$field] ?? null, 'count' => $g['count'] ?? null], (array) ($p['groups'] ?? [])));
                    }
                    // FEAT-VIS-005 pie: share of the total by category. Only meaningful for a few non-negative parts.
                    $groups = array_values(array_filter((array) ($p['groups'] ?? []), fn ($g) => is_array($g) && isset($g['sum']) && is_numeric($g['sum'])));
                    if ($additive($measure, $unit) && count($groups) >= 2 && count($groups) <= 8 && min(array_map(fn ($g) => (float) $g['sum'], $groups)) >= 0) {
                        $options[] = $this->item('pie:'.$e->evidence_key, 'PIE', 'Share of '.$lab($measure).' by '.$lab($dim), $cols, $e, $rankFor($cols),
                            ['field' => 'group', 'label' => $lab($dim), 'unit' => null], ['field' => 'sum', 'label' => $lab($measure), 'unit' => $unit],
                            array_map(fn ($g) => ['group' => $g['group'] ?? null, 'sum' => $g['sum'], 'count' => $g['count'] ?? null], $groups));
                    }
                    break;
                case 'distribution':
                    $measure = $cols[0] ?? null;
                    $unit = $unitFor($measure, null);
                    $options[] = $this->item('histogram:'.$e->evidence_key, 'HISTOGRAM', 'Distribution of '.$lab($measure), $cols, $e, $rankFor($cols),
                        ['field' => 'from', 'label' => $lab($measure), 'unit' => $unit], ['field' => 'count', 'label' => 'Rows', 'unit' => null],
                        array_map(fn ($b) => ['from' => $b['from'] ?? null, 'to' => $b['to'] ?? null, 'count' => $b['count'] ?? null], (array) ($p['bins'] ?? [])));
                    break;
                case 'spearman_correlation':
                    $series = [];
                    foreach (['spearman_rho', 'pearson_r', 'n'] as $k) {
                        if (array_key_exists($k, $p)) {
                            $series[] = ['statistic' => $k, 'value' => $p[$k]];
                        }
                    }
                    $options[] = $this->item('table:'.$e->evidence_key, 'TABLE', 'Association between '.$lab($cols[0] ?? null).' and '.$lab($cols[1] ?? null), $cols, $e, $rankFor($cols),
                        ['field' => 'statistic', 'label' => 'Statistic', 'unit' => null], ['field' => 'value', 'label' => 'Value', 'unit' => null], $series, false);
                    break;
                case 'null_rate':
                    if ($e->value !== null && (float) $e->value > 0) {
                        $missing[] = $e;
                    }
                    break;
            }
        }
        if ($missing !== []) {
            $options[] = [
                'key' => 'missing', 'type' => 'BAR', 'title' => 'Missing values by column', 'source_columns' => [], 'insight_rank' => null,
                'x' => ['field' => 'column', 'label' => 'Column', 'unit' => null], 'y' => ['field' => 'null_rate', 'label' => 'Share missing', 'unit' => '%'],
                'unit_missing' => false,
                'series' => array_map(fn (Evidence $e) => ['column' => $lab(($e->source_columns ?? [])[0] ?? null), 'null_rate' => round(((float) $e->value) * 100, 2)], $missing),
                'evidence_ids' => array_map(fn (Evidence $e) => $e->evidence_key, $missing),
            ];
        }

        return $options;
    }

    /** Charts not yet in the analysis (same type and title), i.e. what the user may add. */
    public function options(Analysis $analysis): array
    {
        $existing = $analysis->visualizations()->get()->map(fn ($v) => $v->chart_type->value.'|'.$v->title)->flip()->all();

        return array_values(array_filter($this->all($analysis), fn ($o) => ! isset($existing[$o['type'].'|'.$o['title']])));
    }

    public function build(Analysis $analysis, string $key): ?array
    {
        foreach ($this->all($analysis) as $option) {
            if ($option['key'] === $key) {
                return $option;
            }
        }

        return null;
    }

    private function item(string $key, string $type, string $title, array $cols, Evidence $e, ?int $rank, array $x, array $y, array $series, ?bool $unitMissing = null): array
    {
        return [
            'key' => $key, 'type' => $type, 'title' => $title, 'source_columns' => $cols, 'insight_rank' => $rank,
            'x' => $x, 'y' => $y, 'unit_missing' => $unitMissing ?? ($y['unit'] === null), 'series' => array_values($series),
            'evidence_ids' => [$e->evidence_key],
        ];
    }
}
