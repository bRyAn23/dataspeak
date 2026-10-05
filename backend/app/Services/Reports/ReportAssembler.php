<?php

namespace App\Services\Reports;

use App\Enums\AnalysisFamily;
use App\Enums\ClaimKind;
use App\Enums\ClaimStatus;
use App\Enums\QualityStatus;
use App\Enums\ReportStatus;
use App\Models\AiGeneration;
use App\Models\Analysis;
use App\Models\Report;
use App\Services\Ai\EvidenceSentences;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Reporting\NarrativeRenderer;
use App\Support\Locale;
use Illuminate\Support\Facades\Lang;

/**
 * RPT-API-001: assembles the executive report from persisted deterministic evidence, insights and
 * visualization specs, plus SUPPORTED claims only. $aiStatus is one of ready, no_valid_claims,
 * unavailable, disabled. No value is computed or invented here (Feature List rule 6).
 */
class ReportAssembler
{
    private const METRIC_PRIORITY = ['row_count', 'sum', 'mean', 'median', 'min', 'max'];

    private const CHART_SENTENCE_METRICS = ['top_group_share', 'top_bottom_mean_difference', 'latest_period_change', 'average_period_growth', 'total_change', 'spearman_correlation', 'cramers_v', 'top_category_share', 'sum', 'mean', 'median'];

    public function assemble(Analysis $analysis, ?AiGeneration $generation = null, string $aiStatus = 'unavailable', string $locale = 'en'): Report
    {
        $locale = Locale::normalize($locale);
        $analysis->load(['evidence', 'insights.evidence', 'selectedVisualizations', 'claims', 'columns.definition']);

        $units = $analysis->columns->mapWithKeys(fn ($c) => [$c->source_name => $c->definition?->unit])->all();
        $display = DisplayLabels::for($analysis, $locale); // FEAT-I18N-002/003: labels in the report language
        $labels = $analysis->columns->mapWithKeys(fn ($c) => [$c->source_name => $display->label($c->definition?->business_name ?? $c->source_name)])->all();

        $summaryMetrics = $analysis->evidence
            ->where('analysis_family', AnalysisFamily::DESCRIPTIVE)
            ->where('quality_status', QualityStatus::ACCEPTABLE)
            ->whereNotNull('value')
            ->sortBy(fn ($e) => array_search($e->metric, self::METRIC_PRIORITY, true) === false ? 99 : array_search($e->metric, self::METRIC_PRIORITY, true))
            ->take(4)
            ->map(fn ($e) => [
                'evidence_key' => $e->evidence_key,
                'label' => __('metrics.summary_label', ['label' => $labels[$e->source_columns[0] ?? ''] ?? ($e->source_columns[0] ?? __('metrics.dataset', [], $locale)), 'metric' => Lang::has('metrics.names.'.$e->metric, $locale) ? __('metrics.names.'.$e->metric, [], $locale) : str_replace('_', ' ', $e->metric)], $locale),
                'display_value' => self::tidy($e->display_value ?? (string) $e->value),
                'unit' => $units[$e->source_columns[0] ?? ''] ?? null,
            ])->values()->all();

        $supported = $analysis->claims->where('status', ClaimStatus::SUPPORTED)->where('in_report', true)->sortBy('position');
        $byKind = fn (ClaimKind $kind) => $supported->where('claim_kind', $kind)->pluck('public_id')->values()->all();

        // Per-chart summaries: the model's checked statements grounded in that chart, else the calculated
        // sentence(s) for the chart's own evidence so every chart has a plain-language reading.
        $grounding = ChartGrounding::for($analysis);
        $resolver = PlaceholderResolver::for($analysis, $locale);
        $renderer = new NarrativeRenderer;
        $supported->loadMissing('evidence');
        $chartSummaries = [];
        $usedTexts = [];
        foreach ($analysis->selectedVisualizations->sortBy('position') as $chart) {
            $position = (int) $chart->position;
            $candidates = [];
            foreach ($supported as $claim) {
                if ($claim->claim_kind === ClaimKind::CAVEAT) {
                    continue;
                }
                $keys = $claim->evidence->pluck('evidence_key')->all();
                if (in_array($position, $grounding->chartsFor($keys), true)) {
                    $candidates[] = [$grounding->claimRank($position, $claim->evidence->pluck('metric')->all()), $claim];
                }
            }
            usort($candidates, fn ($p, $q) => $p[0] <=> $q[0]);
            // The best sentence for a chart is always shown under it, even if a sibling chart over the same
            // columns already used it; only the second line is skipped when it repeats.
            $items = [];
            $add = function (string $text, array $item) use (&$items, &$usedTexts): void {
                if ($items !== [] && isset($usedTexts[$text])) {
                    return;
                }
                if (array_filter($items, fn ($x) => $x['text'] === $text) !== []) {
                    return;
                }
                $usedTexts[$text] = true;
                $items[] = $item;
            };
            foreach ($candidates as [, $claim]) {
                if ($claim->claim_kind === ClaimKind::METRIC || count($items) >= 2) {
                    continue;
                }
                $text = $renderer->render($claim->statement, $resolver);
                $add($text, ['text' => $text, 'claim_id' => $claim->public_id, 'source' => 'narrative']);
            }
            if (count($items) < 2) { // top up with calculated sentences for this chart's own evidence
                foreach ($grounding->evidenceKeysFor($position) as $key) {
                    if (count($items) >= 2) {
                        break;
                    }
                    $e = $resolver->evidence($key);
                    if ($e === null || $e->quality_status === QualityStatus::INSUFFICIENT || ! in_array($e->metric, self::CHART_SENTENCE_METRICS, true)) {
                        continue;
                    }
                    $sentence = EvidenceSentences::for($e, $resolver);
                    if ($sentence !== null) {
                        $text = $renderer->render($sentence, $resolver);
                        $add($text, ['text' => $text, 'claim_id' => null, 'evidence_key' => $key, 'source' => 'calculated']);
                    }
                }
            }
            $chartSummaries[] = ['visualization_id' => $chart->id, 'summary' => $items];
        }

        $sections = [
            [
                'key' => 'executive_summary',
                'title' => __('report.executive_summary', [], $locale),
                'ai_status' => $aiStatus,
                'generation_id' => $generation?->id,
                'model' => $generation?->model,
                'prompt_version' => $generation?->prompt_version,
                'claim_ids' => $byKind(ClaimKind::EXECUTIVE_SUMMARY),
                'note' => match ($aiStatus) {
                    'ready' => null,
                    'no_valid_claims' => 'The AI narrative did not pass validation. Findings below are deterministic.',
                    'disabled' => 'AI narrative is disabled in this deployment. Findings below are deterministic.',
                    default => __('report.ai_unavailable', [], $locale),
                },
            ],
            [
                'key' => 'findings',
                'title' => 'Key findings',
                'insight_ranks' => $analysis->insights->where('is_selected', true)->pluck('rank')->values()->all(),
                'claim_ids' => $byKind(ClaimKind::FINDING),
            ],
            [
                'key' => 'limitations',
                'title' => __('report.data_notes', [], $locale),
                'claim_ids' => $byKind(ClaimKind::CAVEAT),
            ],
            [
                'key' => 'visualizations',
                'title' => 'Charts',
                'visualization_ids' => $analysis->selectedVisualizations->pluck('id')->values()->all(),
                'chart_summaries' => $chartSummaries,
            ],
        ];

        // Data notes are genuine quality findings only: quality-family evidence that is not acceptable,
        // plus completeness and duplicate counts. Descriptive statistics flagged WARNING are not notes.
        $dataNotes = $analysis->evidence
            ->filter(fn ($e) => $e->analysis_family === AnalysisFamily::QUALITY
                && ($e->quality_status !== QualityStatus::ACCEPTABLE || in_array($e->metric, ['completeness', 'duplicate_rows'], true)))
            ->map(fn ($e) => [
                'severity' => $e->quality_status->value,
                'metric' => $e->metric,
                'columns' => $e->source_columns,
                'labels' => array_values(array_map(fn ($c) => $labels[$c] ?? $c, $e->source_columns ?? [])),
                'display_value' => $e->display_value,
            ])->values()->all();

        return $analysis->report()->updateOrCreate([], [
            'status' => ReportStatus::READY,
            'language' => $locale,
            'title' => $analysis->name,
            'generated_at' => now(),
            'claims_total' => $analysis->claims->count(),
            'claims_supported' => $analysis->claims->where('status', ClaimStatus::SUPPORTED)->count(),
            'summary_metrics' => $summaryMetrics,
            'sections' => $sections,
            'data_notes' => $dataNotes,
        ]);
    }

    /** "240.0" -> "240": whole numbers are shown without a trailing decimal in headline figures. */
    private static function tidy(string $display): string
    {
        return preg_replace('/^(-?[\d,]+)\.0(?=\s|$)/u', '$1', $display) ?? $display;
    }
}
