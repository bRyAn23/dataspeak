<?php

namespace App\Jobs;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Enums\JobStatus;
use App\Exceptions\AnalyticsServiceException;
use App\Models\Analysis;
use App\Services\Ai\DisplayLabelTranslator;
use App\Services\Analytics\AnalysisResultPersister;
use App\Services\Analytics\AnalyticsServiceClient;
use App\Services\Analytics\DefinitionPayload;
use App\Services\Analytics\ProgressStore;
use App\Services\Lifecycle\AnalysisLifecycle;
use App\Services\Lifecycle\StaleJobReaper;
use App\Services\Uploads\TransientUploadStore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RUN-API-001 pipeline: analyze -> insights -> visualizations, persist derived results, update progress.
 * The payload carries only the analysis id. The transient CSV is read from disk and removed afterwards.
 */
class RunAnalysisJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $analysisId, public readonly string $jobId) {}

    public function uniqueId(): string
    {
        return (string) $this->analysisId;
    }

    public function handle(
        AnalyticsServiceClient $client,
        AnalysisLifecycle $lifecycle,
        TransientUploadStore $uploads,
        ProgressStore $progress,
        AnalysisResultPersister $persister,
    ): void {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null || $analysis->status !== AnalysisStatus::ANALYZING) {
            return; // deleted or superseded
        }

        $stage = null;
        $keepFile = false;

        try {
            $definitions = DefinitionPayload::for($analysis);
            $options = (array) config('dataspeak.analysis_options');
            $filePath = $uploads->absolutePath($analysis);

            $persister->clear($analysis);

            // Stage 1: deterministic analysis
            $stage = $lifecycle->beginStage($analysis, AnalysisStage::STATISTICAL_ANALYSIS);
            $progress->update($analysis, JobStatus::RUNNING, AnalysisStage::STATISTICAL_ANALYSIS->value, 10);
            $analyzed = $client->analyze($filePath, $analysis->public_id, $definitions, $options);
            $evidenceList = is_array($analyzed['evidence'] ?? null) ? $analyzed['evidence'] : [];
            // json_decode(assoc) turns empty JSON objects into []; restore objects before re-sending.
            $evidenceList = array_map(function (array $item): array {
                foreach (['parameters', 'payload'] as $key) {
                    if (array_key_exists($key, $item) && $item[$key] === []) {
                        $item[$key] = new \stdClass;
                    }
                }

                return $item;
            }, $evidenceList);
            $evidenceIds = $persister->persistEvidence($analysis, $evidenceList);
            $lifecycle->completeStage($stage, ['evidence_count' => count($evidenceIds), 'families_run' => $analyzed['summary']['families_run'] ?? []]);
            $progress->update($analysis, JobStatus::RUNNING, AnalysisStage::INSIGHT_DETECTION->value, 55);

            // Stage 2: insights
            $stage = $lifecycle->beginStage($analysis, AnalysisStage::INSIGHT_DETECTION);
            $insightsResponse = $client->insights($analysis->public_id, $evidenceList, $definitions, ['max_insights' => $options['max_insights'] ?? 10]);
            $insights = is_array($insightsResponse['insights'] ?? null) ? $insightsResponse['insights'] : [];
            $persister->persistInsights($analysis, $insights, $evidenceIds);
            $lifecycle->completeStage($stage, ['insight_count' => count($insights)]);
            $progress->update($analysis, JobStatus::RUNNING, AnalysisStage::VISUALIZATION->value, 80);

            // Stage 3: visualization recommendations
            $stage = $lifecycle->beginStage($analysis, AnalysisStage::VISUALIZATION);
            $vizResponse = $client->recommendVisualizations($analysis->public_id, $evidenceList, $insights, $definitions);
            $visualizations = is_array($vizResponse['visualizations'] ?? null) ? $vizResponse['visualizations'] : [];
            $persister->persistVisualizations($analysis, $visualizations);
            $lifecycle->completeStage($stage, ['visualization_count' => count($visualizations)]);
            $stage = null;

            // FEAT-I18N-002: the report is English only. Headers and category values in other scripts get English
            // display labels now, so insights, charts and the narrative prompt all use them. Never fails the run.
            $progress->update($analysis, JobStatus::RUNNING, AnalysisStage::VISUALIZATION->value, 92);
            try {
                $translator = app(DisplayLabelTranslator::class);
                $translator->translate($analysis, 'en');
                if (($analysis->report_language ?? 'en') !== 'en') {
                    $translator->translate($analysis, $analysis->report_language);
                }
            } catch (Throwable $e) {
                Log::warning('labels.translate_failed', ['analysis_id' => $analysis->public_id, 'method' => $e::class]);
            }

            $lifecycle->transition($analysis, AnalysisStatus::ANALYZED, AnalysisStage::VISUALIZATION);
            $progress->update($analysis, JobStatus::SUCCEEDED, AnalysisStage::VISUALIZATION->value, 100);

            // One-click flow: continue straight into report generation so the user never has to ask for it.
            if (config('dataspeak.auto_report', true)) {
                $lifecycle->transition($analysis, AnalysisStatus::REPORTING, AnalysisStage::AI_SYNTHESIS);
                $progress->update($analysis, JobStatus::RUNNING, AnalysisStage::AI_SYNTHESIS->value, 0);
                GenerateReportJob::dispatch($analysis->id, $this->jobId, $analysis->report_language ?? 'en');
            }
        } catch (Throwable $e) {
            $code = $e instanceof AnalyticsServiceException ? $e->errorCode : 'ANALYSIS_JOB_FAILED';
            $message = $e instanceof AnalyticsServiceException ? $e->getMessage() : 'The analysis could not be completed.';
            $keepFile = $e instanceof AnalyticsServiceException && $e->isTransient();

            if ($stage !== null) {
                $lifecycle->failStage($stage, $code);
            }
            $failedStage = $stage?->stage ?? AnalysisStage::STATISTICAL_ANALYSIS;
            $lifecycle->fail($analysis->refresh(), $failedStage, $code, $message);
            $progress->update($analysis, JobStatus::FAILED, $failedStage->value, 100);

            Log::error('analysis.run_failed', ['analysis_id' => $analysis->public_id, 'code' => $code, 'method' => $e::class]);
        } finally {
            if (! $keepFile) {
                $uploads->delete($analysis, 'run_finished');
            }
        }
    }

    /**
     * Queue-level failure (hard timeout, MaxAttemptsExceeded after a redelivery, exception outside handle()):
     * the analysis must not stay in ANALYZING.
     */
    public function failed(?Throwable $e = null): void
    {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null || $analysis->status !== AnalysisStatus::ANALYZING) {
            return;
        }
        Log::error('analysis.job_failed', ['analysis_id' => $analysis->public_id, 'method' => $e ? $e::class : null]);
        app(StaleJobReaper::class)->reap($analysis, force: true);
    }
}
