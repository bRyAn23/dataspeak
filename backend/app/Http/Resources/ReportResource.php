<?php

namespace App\Http\Resources;

use App\Enums\ClaimStatus;
use App\Jobs\TranslateReportJob;
use App\Services\Ai\EvidenceSentences;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Reporting\NarrativeRenderer;
use App\Services\Reports\DisplayLabels;
use App\Support\ChartThemes;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;

/** RPT-API-002: executive report. Only SUPPORTED claims are exposed, rendered from evidence. @mixin \App\Models\Report */
class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The model's narrative keeps the language it was written in until it is rewritten (the frontend triggers
        // that automatically when the interface language differs). Everything software writes - chart titles and
        // readings, key-figure labels, data notes, insights - follows the language the client asked for.
        $reportLocale = Locale::normalize($this->language ?? 'en');
        $displayLocale = Locale::normalize(app()->getLocale());

        return $this->build($request, $reportLocale, $displayLocale);
    }

    private function build(Request $request, string $reportLocale, string $displayLocale): array
    {
        $analysis = $this->analysis;
        $analysis->loadMissing(['columns.definition', 'evidence']);
        $narrative = PlaceholderResolver::for($analysis, $reportLocale);
        $display = $displayLocale === $reportLocale ? $narrative : PlaceholderResolver::for($analysis, $displayLocale);
        $labels = DisplayLabels::for($analysis, $displayLocale);
        $columnLabels = $analysis->columns->mapWithKeys(fn ($c) => [$c->source_name => $labels->label($c->definition?->business_name ?? $c->source_name)])->all();
        $evidenceByKey = $analysis->evidence->keyBy('evidence_key');
        $renderer = new NarrativeRenderer;
        $summary = collect($this->sections ?? [])->firstWhere('key', 'executive_summary') ?? [];
        $seenTexts = [];
        $chartSummaries = collect(collect($this->sections ?? [])->firstWhere('key', 'visualizations')['chart_summaries'] ?? [])->keyBy('visualization_id');
        $claims = $analysis->claims()
            ->where('status', ClaimStatus::SUPPORTED)->where('in_report', true)
            ->orderBy('position')->with(['evidence', 'aiGeneration'])->get();

        // Key figures: the label is "<business name> <metric>" in the display language; values are stored.
        $summaryMetrics = array_map(function (array $m) use ($evidenceByKey, $columnLabels, $displayLocale) {
            $e = $evidenceByKey->get($m['evidence_key'] ?? '');
            if ($e !== null) {
                $column = $e->source_columns[0] ?? null;
                $metricWord = Lang::has('metrics.names.'.$e->metric, $displayLocale) ? __('metrics.names.'.$e->metric, [], $displayLocale) : str_replace('_', ' ', $e->metric);
                $m['label'] = __('metrics.summary_label', ['label' => $column !== null ? ($columnLabels[$column] ?? $column) : __('metrics.dataset', [], $displayLocale), 'metric' => $metricWord], $displayLocale);
            }

            return $m;
        }, (array) ($this->summary_metrics ?? []));

        // Data notes: column labels in the display language.
        $dataNotes = array_map(function (array $n) use ($columnLabels) {
            $n['labels'] = array_values(array_map(fn ($c) => $columnLabels[$c] ?? $c, (array) ($n['columns'] ?? [])));

            return $n;
        }, (array) ($this->data_notes ?? []));

        // Narrative claims: a verified translation for the display language when the claim has one, else the
        // original rendered in the report's language (never a mixed sentence).
        $claimResources = $claims->map(fn ($c) => new ClaimResource($c, isset(((array) ($c->statement_translations ?? []))[$displayLocale]) ? $display : $narrative, $displayLocale));
        $claimTexts = [];
        foreach ($claimResources as $res) {
            $claimTexts[$res->public_id] = $res->toArray($request)['statement'];
        }
        $translatedCount = $claims->filter(fn ($c) => isset(((array) ($c->statement_translations ?? []))[$displayLocale]))->count();

        // Chart readings: calculated sentences are re-rendered in the display language; narrative ones follow their claim.
        $readings = function (int $visualizationId) use ($chartSummaries, $evidenceByKey, $display, $displayLocale, $renderer, $claimTexts): array {
            $items = $chartSummaries->get($visualizationId)['summary'] ?? [];

            return array_values(array_map(function (array $item) use ($evidenceByKey, $display, $displayLocale, $renderer, $claimTexts) {
                if (($item['source'] ?? null) === 'narrative' && isset($item['claim_id'], $claimTexts[$item['claim_id']])) {
                    $item['text'] = $claimTexts[$item['claim_id']];
                }
                if (($item['source'] ?? null) === 'calculated' && isset($item['evidence_key']) && $evidenceByKey->has($item['evidence_key'])) {
                    $sentence = EvidenceSentences::for($evidenceByKey->get($item['evidence_key']), $display, $displayLocale);
                    if ($sentence !== null) {
                        $item['text'] = $renderer->render($sentence, $display);
                    }
                }

                return $item;
            }, $items));
        };

        return [
            'id' => (string) $this->id,
            'analysis_id' => $analysis->public_id,
            'status' => $this->status->value,
            'language' => $reportLocale,
            'chart_theme' => ChartThemes::normalize($analysis->chart_theme), // FEAT-VIS-004
            'display_language' => $displayLocale,
            'translation' => [
                'complete' => $displayLocale === $reportLocale || $translatedCount === $claims->count(),
                'translated' => $displayLocale === $reportLocale ? $claims->count() : $translatedCount,
                'total' => $claims->count(),
                'in_progress' => $displayLocale !== $reportLocale && TranslateReportJob::inProgress($analysis, $displayLocale),
            ],
            'title' => $this->title,
            'dataset_name' => $analysis->source_file_name,
            'generated_at' => $this->generated_at?->toIso8601ZuluString(),
            'claims_total' => $this->claims_total,
            'claims_supported' => $this->claims_supported,
            'ai' => [
                'status' => $summary['ai_status'] ?? 'unavailable',
                'note' => $summary['note'] ?? null,
                'model' => $summary['model'] ?? null,
                'prompt_version' => $summary['prompt_version'] ?? null,
                'generation_id' => isset($summary['generation_id']) ? (string) $summary['generation_id'] : null,
            ],
            'summary_metrics' => $summaryMetrics,
            'sections' => $this->sections ?? [],
            'data_notes' => $dataNotes,
            'insights' => $analysis->insights()->with(['evidence.metricColumn.definition', 'evidence.dimensionColumn.definition'])->get()->map(fn ($i) => (new InsightResource($i, $display))->toArray($request))->values(),
            'visualizations' => $analysis->selectedVisualizations()->with('insight')->orderBy('position')->get()->map(fn ($v) => (new VisualizationResource($v))->toArray($request) + [
                'summary' => $readings((int) $v->id),
            ])->values(),
            // Two claims resting on different evidence can render to the same words; show each sentence once.
            'claims' => $claimResources->values()->filter(function ($res, $i) use (&$seenTexts, $request) {
                $text = $res->toArray($request)['statement'];
                if (isset($seenTexts[$text])) {
                    return false;
                }
                $seenTexts[$text] = true;

                return true;
            })->values(),
        ];
    }
}
