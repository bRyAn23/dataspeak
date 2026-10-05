<?php

namespace App\Jobs;

use App\Enums\AiGenerationStatus;
use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Enums\ClaimKind;
use App\Enums\ClaimStatus;
use App\Enums\JobStatus;
use App\Models\AiGeneration;
use App\Models\Analysis;
use App\Services\Ai\AiGateway;
use App\Services\Ai\DisplayLabelTranslator;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Analytics\ProgressStore;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Services\Lifecycle\StaleJobReaper;
use App\Services\Reports\ChartGrounding;
use App\Services\Reports\DisplayLabels;
use App\Services\Reports\ReportAssembler;
use App\Services\Validation\Contracts\ClaimValidator;
use App\Services\Validation\ValidationResult;
use App\Support\Locale;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RPT-API-001 pipeline: AI_SYNTHESIS (Qwen interprets evidence) -> CLAIM_VALIDATION (rule-based)
 * -> REPORTING (assemble with SUPPORTED claims only) -> COMPLETED.
 * When the model is unavailable or no claim survives validation the report is still assembled from
 * deterministic content with ai_status set accordingly; no narrative is fabricated.
 */
class GenerateReportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $analysisId, public readonly string $jobId, public readonly string $language = 'en')
    {
        $this->timeout = (int) config('dataspeak.ai.job_timeout_seconds', 900);
    }

    public int $timeout = 900;

    public function uniqueId(): string
    {
        return 'report:'.$this->analysisId;
    }

    private AiGateway $gateway;

    public function handle(
        AiGateway $gateway,
        ClaimValidator $validator,
        ReportAssembler $assembler,
        AnalysisLifecycle $lifecycle,
        ProgressStore $progress,
    ): void {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null || $analysis->status !== AnalysisStatus::REPORTING) {
            return;
        }

        $this->gateway = $gateway;
        $stage = null;
        try {
            // A regenerated report replaces the previous narrative.
            DB::transaction(function () use ($analysis): void {
                DB::table('claim_evidence')->whereIn('claim_id', $analysis->claims()->pluck('id'))->delete();
                $analysis->claims()->delete();
            });

            // Stage 1: AI synthesis, in the report language (FEAT-I18N-003)
            $locale = Locale::normalize($this->language);
            $stage = $lifecycle->beginStage($analysis, AnalysisStage::AI_SYNTHESIS);
            $progress->update($analysis, JobStatus::RUNNING, AnalysisStage::AI_SYNTHESIS->value, 5);
            try {
                // Labels and category values in that language; the run already did this for its own language.
                if (! isset(DisplayLabels::normalizeStored($analysis->label_translations)[$locale])) {
                    app(DisplayLabelTranslator::class)->translate($analysis, $locale);
                }
            } catch (Throwable $e) {
                Log::warning('labels.translate_failed', ['analysis_id' => $analysis->public_id, 'method' => $e::class]);
            }
            $analysis->refresh();
            $progress->update($analysis, JobStatus::RUNNING, AnalysisStage::AI_SYNTHESIS->value, 10);
            $resolver = PlaceholderResolver::for($analysis, $locale);
            $generation = $gateway->interpret($analysis, $resolver, true, $locale);
            if ($generation->status === AiGenerationStatus::SUCCEEDED) {
                $lifecycle->completeStage($stage, ['generation_id' => $generation->id, 'claims_returned' => count($generation->response_payload['claims'] ?? [])]);
            } else {
                $lifecycle->failStage($stage, $generation->error_code ?? 'AI_FAILED');
            }
            $progress->update($analysis, JobStatus::RUNNING, AnalysisStage::CLAIM_VALIDATION->value, 55);

            // Stage 2: claim validation and persistence
            $stage = $lifecycle->beginStage($analysis, AnalysisStage::CLAIM_VALIDATION);
            $aiStatus = $this->aiStatus($generation);
            $counts = ['validated' => 0, 'supported' => 0, 'rejected' => 0, 'needs_review' => 0, 'repaired' => 0];
            if ($generation->status === AiGenerationStatus::SUCCEEDED) {
                $counts = $this->persistClaims($analysis, $generation, $resolver, $validator);
                $aiStatus = $counts['supported'] > 0 ? 'ready' : 'no_valid_claims';
            }
            $lifecycle->completeStage($stage, $counts + ['ai_status' => $aiStatus]);
            $progress->update($analysis, JobStatus::RUNNING, AnalysisStage::REPORTING->value, 80);

            // Stage 3: assemble
            $stage = $lifecycle->beginStage($analysis, AnalysisStage::REPORTING);
            $report = $assembler->assemble($analysis->refresh(), $generation, $aiStatus, $locale);
            $lifecycle->completeStage($stage, ['claims_total' => $report->claims_total, 'claims_supported' => $report->claims_supported, 'ai_status' => $aiStatus]);
            $stage = null;

            $lifecycle->transition($analysis, AnalysisStatus::COMPLETED, AnalysisStage::COMPLETE);
            $progress->update($analysis, JobStatus::SUCCEEDED, AnalysisStage::COMPLETE->value, 100);
            if (config('dataspeak.ai.pretranslate', true)) {
                TranslateReportJob::dispatchForOtherLanguages($analysis, $locale); // switching languages later is instant
            }
            Log::info('report.generated', ['analysis_id' => $analysis->public_id, 'code' => $aiStatus, 'row_count' => $report->claims_supported]);
        } catch (Throwable $e) {
            if ($stage !== null) {
                $lifecycle->failStage($stage, 'REPORT_GENERATION_FAILED');
            }
            $lifecycle->fail($analysis->refresh(), $stage?->stage ?? AnalysisStage::REPORTING, 'REPORT_GENERATION_FAILED', 'The report could not be generated.');
            $progress->update($analysis, JobStatus::FAILED, AnalysisStage::REPORTING->value, 100);
            Log::error('report.failed', ['analysis_id' => $analysis->public_id, 'method' => $e::class, 'at' => basename($e->getFile()).':'.$e->getLine()]);
        }
    }

    private function aiStatus(AiGeneration $generation): string
    {
        return match ($generation->error_code) {
            'AI_DISABLED' => 'disabled',
            default => 'unavailable',
        };
    }

    /** Reason codes that a single model rewrite can fix. Anything else is final. */
    private const REPAIRABLE = ['DIGITS_OUTSIDE_PLACEHOLDER', 'PLACEHOLDER_FIELD_NOT_ALLOWED', 'PLACEHOLDER_UNRESOLVABLE', 'PLACEHOLDER_UNKNOWN_EVIDENCE', 'WRONG_LANGUAGE'];

    /** @return array{validated: int, supported: int, rejected: int, needs_review: int, repaired: int} */
    private function persistClaims(Analysis $analysis, AiGeneration $generation, PlaceholderResolver $resolver, ClaimValidator $validator): array
    {
        $counts = ['validated' => 0, 'supported' => 0, 'rejected' => 0, 'needs_review' => 0, 'repaired' => 0];
        $items = array_merge((array) ($generation->response_payload['claims'] ?? []), (array) ($generation->response_payload['limitations'] ?? []));

        // The model sometimes repeats a sentence under several kinds or in both lists: keep the first
        // occurrence of each text. Placement decides the kind afterwards, so the kind is not part of the key.
        $seen = [];
        $items = array_values(array_filter($items, function (array $item) use (&$seen): bool {
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($item['text'] ?? ''))) ?? '');
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));

        // First pass: validate; keep the validator's normalised claim; collect repairable rejections.
        $results = array_map(fn (array $item) => $validator->validate($item, $resolver), $items);
        foreach ($results as $i => $result) {
            $items[$i] = $result->claim ?? $items[$i];
        }
        $repairable = [];
        foreach ($results as $i => $result) {
            $codes = array_map(fn ($r) => explode(':', $r)[0], $result->reasons);
            if ($result->status === ClaimStatus::REJECTED && $codes !== [] && array_diff($codes, self::REPAIRABLE) === []) {
                $repairable[$i] = $items[$i];
            }
        }
        if ($repairable !== []) {
            $rewritten = $this->gateway->repair($generation, array_values($repairable), $resolver);
            $byKey = [];
            foreach ($rewritten as $claim) {
                $byKey[(string) ($claim['claim_key'] ?? '')] = $claim;
            }
            foreach ($repairable as $i => $original) {
                $candidate = $byKey[(string) ($original['claim_key'] ?? '')] ?? null;
                if ($candidate === null) {
                    continue;
                }
                $check = $validator->validate($candidate, $resolver);
                if ($check->status !== ClaimStatus::REJECTED) {
                    $items[$i] = $check->claim ?? $candidate;
                    $results[$i] = new ValidationResult($check->status, array_merge($check->reasons, ['REPAIRED']), $check->method);
                    $counts['repaired']++;
                }
            }
        }

        // Placement is decided by software, not by the model's labels: statements grounded in a charted
        // insight form the executive summary (chart order, one per chart), other business statements are
        // findings, plain descriptive figures are METRIC statements (the KPI cards already show them), and
        // caveats that merely repeat the data notes are kept out of the narrative.
        [$items, $results] = $this->place($analysis, $items, $results, $resolver);

        $position = 0;
        DB::transaction(function () use ($analysis, $generation, $resolver, $items, $results, &$counts, &$position): void {
            foreach ($items as $i => $item) {
                $result = $results[$i];
                $ids = array_values(array_unique(array_filter((array) ($item['evidence_ids'] ?? []), fn ($id) => $resolver->has($id))));
                $primary = $ids[0] ?? null;
                $counts['validated']++;
                $counts[match ($result->status) {
                    ClaimStatus::SUPPORTED => 'supported', ClaimStatus::REJECTED => 'rejected', default => 'needs_review',
                }]++;

                $claim = $analysis->claims()->create([
                    'ai_generation_id' => $generation->id,
                    'claim_kind' => ClaimKind::tryFrom((string) ($item['claim_kind'] ?? '')) ?? ClaimKind::FINDING,
                    'statement' => (string) ($item['text'] ?? ''),
                    'claimed_value' => null,
                    'computed_value' => $primary !== null ? mb_substr((string) $resolver->resolve($primary, 'display_value'), 0, 128) : null,
                    'status' => $result->status,
                    'validation_method' => $result->method,
                    'validation_notes' => mb_substr(implode(' | ', $result->reasons), 0, 500) ?: null,
                    'in_report' => $result->accepted() && ! in_array('NOT_IN_NARRATIVE', $result->reasons, true),
                    'position' => ++$position,
                ]);
                foreach ($ids as $k => $id) {
                    $claim->evidence()->attach($resolver->evidence($id)->id, ['is_primary' => $k === 0, 'created_at' => now()]);
                }
            }
        });

        return $counts;
    }

    private const NARRATIVE_METRICS = ['top_group_share', 'top_category_share', 'top_bottom_mean_difference', 'latest_period_change', 'average_period_growth', 'total_change', 'trend_slope', 'spearman_correlation', 'cramers_v', 'group_difference_test'];

    private const NOTE_ONLY_METRICS = ['row_count', 'count', 'completeness', 'distinct_count'];

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<ValidationResult>  $results
     * @return array{0: list<array<string, mixed>>, 1: list<ValidationResult>}
     */
    private function place(Analysis $analysis, array $items, array $results, PlaceholderResolver $resolver): array
    {
        $grounding = ChartGrounding::for($analysis);

        // Pass 1: ground every supported, non-caveat claim and rank how well it explains its chart.
        $info = [];
        foreach ($items as $i => $item) {
            $ids = array_values(array_filter((array) ($item['evidence_ids'] ?? []), fn ($id) => $resolver->has($id)));
            $metrics = array_values(array_filter(array_map(fn ($id) => $resolver->evidence($id)?->metric, $ids)));
            $chart = $grounding->chartFor($ids);
            $info[$i] = ['ids' => $ids, 'metrics' => $metrics, 'chart' => $chart,
                'rank' => $chart !== null ? $grounding->claimRank($chart, $metrics) : 999,
                'summary_ok' => array_diff($metrics, ChartGrounding::NOT_FOR_SUMMARY) !== []];
        }
        // Pass 2: one executive-summary sentence per chart: the best-ranked eligible claim.
        $summaryWinner = [];
        foreach ($info as $i => $meta) {
            $kind0 = (string) ($items[$i]['claim_kind'] ?? 'FINDING');
            if ($kind0 === ClaimKind::CAVEAT->value || $results[$i]->status !== ClaimStatus::SUPPORTED || $meta['chart'] === null || ! $meta['summary_ok']) {
                continue;
            }
            $current = $summaryWinner[$meta['chart']] ?? null;
            if ($current === null || $meta['rank'] < $info[$current]['rank']) {
                $summaryWinner[$meta['chart']] = $i;
            }
        }
        $summaryItems = array_slice(array_values($summaryWinner), 0, 5);

        $ordered = [];
        foreach ($items as $i => $item) {
            $result = $results[$i];
            $kind = (string) ($item['claim_kind'] ?? 'FINDING');
            $ids = $info[$i]['ids'];
            $metrics = $info[$i]['metrics'];
            $reasons = $result->reasons;
            $rank = PHP_INT_MAX;

            if ($kind === ClaimKind::CAVEAT->value) {
                if ($ids !== [] && array_diff($metrics, self::NOTE_ONLY_METRICS) === []) {
                    $reasons[] = 'NOT_IN_NARRATIVE';
                }
                $rank = PHP_INT_MAX - 1;
            } elseif ($result->status === ClaimStatus::SUPPORTED) {
                $chart = $info[$i]['chart'];
                $narrative = array_intersect($metrics, self::NARRATIVE_METRICS) !== [];
                if (in_array($i, $summaryItems, true)) {
                    $kind = ClaimKind::EXECUTIVE_SUMMARY->value;
                    $rank = $chart;
                } elseif ($narrative || $chart !== null) {
                    $kind = ClaimKind::FINDING->value;
                    $rank = 1000 + ($chart ?? 500);
                } else {
                    $kind = ClaimKind::METRIC->value;
                    $reasons[] = 'NOT_IN_NARRATIVE';
                    $rank = 2000;
                }
                if ($kind !== (string) ($item['claim_kind'] ?? '')) {
                    $reasons[] = 'PLACED_BY_SOFTWARE:'.$kind;
                }
            }

            $ordered[] = [$rank, $i, array_merge($item, ['claim_kind' => $kind]), new ValidationResult($result->status, array_values(array_unique($reasons)), $result->method, $result->claim)];
        }
        usort($ordered, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return [array_map(fn ($o) => $o[2], $ordered), array_map(fn ($o) => $o[3], $ordered)];
    }

    /**
     * Queue-level failure (hard timeout, MaxAttemptsExceeded after a redelivery, exception outside handle()):
     * the analysis must not stay in REPORTING.
     */
    public function failed(?Throwable $e = null): void
    {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null || $analysis->status !== AnalysisStatus::REPORTING) {
            return;
        }
        Log::error('report.job_failed', ['analysis_id' => $analysis->public_id, 'method' => $e ? $e::class : null]);
        app(StaleJobReaper::class)->reap($analysis, force: true);
    }
}
