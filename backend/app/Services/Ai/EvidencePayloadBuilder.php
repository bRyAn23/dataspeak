<?php

namespace App\Services\Ai;

use App\Enums\AnalysisFamily;
use App\Enums\QualityStatus;
use App\Models\Analysis;
use App\Services\Reports\ChartGrounding;
use App\Services\Reports\ChartTitles;
use App\Services\Reports\DisplayLabels;

/**
 * Reduces persisted evidence and insights to the minimal JSON the model may see (prompt Section 6).
 * Included: evidence ids, metrics, methods, display values, units, business labels, quality, insight
 * text and rank, dataset counts. Excluded: rows, samples, file names, user identity. Trimmed to fit
 * the context window; omissions are recorded on the generation.
 */
class EvidencePayloadBuilder
{
    public const CONTEXT_METRICS = ['top_group_share', 'top_bottom_mean_difference', 'average_period_growth', 'total_change', 'latest_period_change', 'spearman_correlation', 'top_category_share'];

    public const MAX_CONTEXT = 12;

    /** @return array{payload: array<string, mixed>, evidence_keys: list<string>, insight_ranks: list<int>, omitted: array<string, mixed>, estimated_tokens: int} */
    public function build(Analysis $analysis, PlaceholderResolver $resolver): array
    {
        $analysis->loadMissing(['insights.evidence', 'insights.visualizations', 'evidence', 'selectedVisualizations']);
        $maxInsights = (int) config('dataspeak.ai.max_insights_in_prompt', 10);
        $budget = (int) config('services.ollama.num_ctx', 8192) - (int) config('dataspeak.ai.prompt_overhead_tokens', 1200);

        $insights = $analysis->insights->where('is_selected', true)->sortBy('rank')->values();
        $summaryKeys = $analysis->evidence
            ->where('analysis_family', AnalysisFamily::DESCRIPTIVE)->where('quality_status', QualityStatus::ACCEPTABLE)
            ->whereNotNull('value')->whereIn('metric', ['row_count', 'sum', 'mean', 'median'])->take(6)->pluck('evidence_key')->all();
        // Context evidence: acceptable comparative/trend/association results even when below insight
        // thresholds, so an executive summary can describe the overall picture with placeholders.
        $contextKeys = $analysis->evidence
            ->where('quality_status', QualityStatus::ACCEPTABLE)
            ->whereIn('metric', self::CONTEXT_METRICS)
            ->whereNotNull('value')
            ->sortBy([fn ($a, $b) => abs((float) $b->value) <=> abs((float) $a->value)])
            ->take(self::MAX_CONTEXT)
            ->pluck('evidence_key')->all();
        $qualityKeys = $analysis->evidence
            ->filter(fn ($e) => $e->quality_status !== QualityStatus::ACCEPTABLE || $e->analysis_family === AnalysisFamily::QUALITY)
            ->sortBy(fn ($e) => in_array($e->metric, ['completeness', 'duplicate_rows'], true) ? 0 : 1)
            ->take(10)->pluck('evidence_key')->all();

        // Dataset-level figures are exposed only through evidence ids, never as raw numbers, so the
        // model has a placeholder for every value it may want to mention.
        $datasetEvidence = $analysis->evidence->whereIn('metric', ['row_count', 'completeness', 'duplicate_rows'])
            ->mapWithKeys(fn ($e) => [$e->metric => $e->evidence_key])->all();
        $dataset = [
            'column_count' => $analysis->column_count,
            'figures_as_evidence' => $datasetEvidence,
            'note' => 'Cite row count, completeness and duplicates through the evidence ids above; never type their values.',
        ];

        $omittedRanks = [];
        $kept = $insights->take($maxInsights);
        $omittedRanks = $insights->slice($maxInsights)->pluck('rank')->values()->all();

        while (true) {
            $payload = $this->assemble($analysis, $dataset, $kept, $summaryKeys, $qualityKeys, $contextKeys, $resolver);
            $tokens = (int) ceil(mb_strlen(json_encode($payload, JSON_UNESCAPED_UNICODE)) / 4);
            if ($tokens <= $budget || $kept->isEmpty()) {
                break;
            }
            $dropped = $kept->last();
            $omittedRanks[] = $dropped->rank;
            $kept = $kept->slice(0, -1)->values();
        }

        return [
            'payload' => $payload,
            'evidence_keys' => array_values(array_map(fn ($e) => $e['id'], $payload['evidence'])),
            'insight_ranks' => $kept->pluck('rank')->values()->all(),
            'omitted' => ['insight_ranks' => array_values(array_unique($omittedRanks))],
            'estimated_tokens' => $tokens,
        ];
    }

    /** @return array<string, mixed> */
    private function assemble(Analysis $analysis, array $dataset, $insights, array $summaryKeys, array $qualityKeys, array $contextKeys, PlaceholderResolver $resolver): array
    {
        $display = DisplayLabels::for($analysis, $resolver->locale());
        $title = fn (?string $t) => $display->text(ChartTitles::localize($t, $resolver->locale()));
        $keys = [];
        $insightItems = [];
        foreach ($insights as $i) {
            $ids = array_values(array_unique(array_merge([$i->evidence?->evidence_key], (array) $i->supporting_evidence_keys)));
            $ids = array_values(array_filter($ids, fn ($k) => $k !== null && $resolver->has($k)));
            $keys = array_merge($keys, $ids);
            $chart = $i->visualizations->firstWhere('is_selected', true); // deselected charts are not described
            $insightItems[] = array_filter([
                'rank' => $i->rank,
                'type' => strtolower($i->insight_type->value),
                'chart' => $chart ? ['type' => strtolower($chart->chart_type->value), 'title' => $this->placeholderize($title($chart->title), $ids, $resolver) ?? strtolower($chart->chart_type->value).' chart'] : null,
                'headline' => $this->placeholderize($display->text($i->headline), $ids, $resolver),
                'description' => $this->placeholderize($display->text($i->description), $ids, $resolver),
                'evidence_ids' => $ids,
            ], fn ($v) => $v !== null);
        }
        $keys = array_values(array_unique(array_merge($keys, $summaryKeys, $contextKeys, $qualityKeys)));

        $evidence = [];
        foreach ($keys as $key) {
            $e = $resolver->evidence($key);
            if ($e === null) {
                continue;
            }
            $view = $resolver->viewFor($key);
            // Ready-to-copy placeholder strings (prompt v2): the model copies these instead of typing numbers.
            $placeholders = [];
            foreach ((array) config('dataspeak.ai.placeholder_fields') as $field) {
                if (($view[$field] ?? null) !== null && $view[$field] !== '') {
                    $placeholders[$field] = '{'.$key.'.'.$field.'}';
                }
            }
            $evidence[] = array_filter([
                'id' => $key,
                'family' => strtolower($e->analysis_family->value),
                'metric' => $e->metric,
                'method' => $e->method,
                'display_value' => $view['display_value'],
                'value' => $e->value !== null ? (float) $e->value : null,
                'unit' => $view['unit'],
                'label' => $view['label'],
                'dimension' => $view['dimension'],
                'group' => $view['group'],
                'period' => $view['period'],
                'period_from' => $view['period_from'],
                'period_to' => $view['period_to'],
                'quality' => strtolower($e->quality_status->value),
                'placeholders' => $placeholders,
                'sentence' => EvidenceSentences::for($e, $resolver),
            ], fn ($v) => $v !== null);
        }

        $grounding = ChartGrounding::for($analysis);
        $charts = [];
        foreach ($analysis->selectedVisualizations->sortBy('position') as $chart) {
            $keys = array_values(array_filter($grounding->evidenceKeysFor((int) $chart->position), fn ($k) => in_array($k, $keys, true)));
            $charts[] = ['position' => (int) $chart->position, 'type' => strtolower($chart->chart_type->value), 'title' => $this->placeholderize($title($chart->title), (array) $chart->evidence_keys, $resolver) ?? strtolower($chart->chart_type->value).' chart', 'evidence_ids' => $keys];
        }

        return ['dataset' => $dataset, 'charts' => $charts, 'charted_insight_ranks' => array_values(array_filter(array_map(fn ($i) => isset($i['chart']) ? $i['rank'] : null, $insightItems))), 'insights' => $insightItems, 'evidence' => $evidence, 'summary_metric_ids' => $summaryKeys, 'context_metric_ids' => $contextKeys];
    }

    /**
     * Replaces numbers in deterministic insight text with the placeholder of the evidence they came from.
     * Text that still contains a digit afterwards is dropped: the model must not see literal values.
     */
    private function placeholderize(?string $text, array $evidenceIds, PlaceholderResolver $resolver): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $pairs = [];
        foreach ($evidenceIds as $id) {
            $view = $resolver->viewFor($id);
            foreach (['display_value', 'period_from', 'period_to'] as $field) {
                $value = $view[$field] ?? null;
                if ($value !== null && $value !== '' && preg_match('/\\p{N}/u', $value)) {
                    $pairs[$value] = '{'.$id.'.'.$field.'}';
                }
            }
        }
        uksort($pairs, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($pairs as $value => $placeholder) {
            $text = str_replace($value, $placeholder, $text);
        }
        $stripped = preg_replace('/\\{ev_[a-f0-9]{12}\\.[a-z_]+\\}/u', '', $text) ?? $text;

        return preg_match('/\\p{N}/u', $stripped) ? null : $text;
    }
}
