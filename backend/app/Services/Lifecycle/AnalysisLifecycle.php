<?php

namespace App\Services\Lifecycle;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use App\Enums\StageStatus;
use App\Exceptions\LifecycleConflictException;
use App\Models\Analysis;
use App\Models\AnalysisStageRecord;
use Illuminate\Support\Facades\Log;

/**
 * The single place where analysis state transitions are decided (Specification Section 11).
 * Controllers and jobs call transition(); nothing else writes analyses.status.
 */
class AnalysisLifecycle
{
    /** @var array<string, list<string>> allowed target statuses per current status */
    public const TRANSITIONS = [
        'CREATED' => ['UPLOADED'],
        'UPLOADED' => ['UPLOADED', 'VALIDATED', 'FAILED'],
        'VALIDATED' => ['UPLOADED', 'PROFILED', 'FAILED'],
        'PROFILED' => ['UPLOADED', 'PROFILED', 'DEFINED', 'FAILED'],
        'DEFINED' => ['UPLOADED', 'PROFILED', 'DEFINED', 'ANALYZING', 'FAILED'],
        'ANALYZING' => ['ANALYZED', 'FAILED'],
        'ANALYZED' => ['UPLOADED', 'DEFINED', 'ANALYZING', 'REPORTING', 'FAILED'],
        'REPORTING' => ['COMPLETED', 'FAILED'],
        'COMPLETED' => ['UPLOADED', 'DEFINED', 'ANALYZING', 'REPORTING'],
        'FAILED' => ['UPLOADED', 'PROFILED', 'DEFINED', 'ANALYZING', 'REPORTING'],
    ];

    public function canTransition(Analysis $analysis, AnalysisStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$analysis->status->value] ?? [], true);
    }

    /** Moves the analysis to $to, records the stage, and persists. Throws 409 when not allowed. */
    public function transition(Analysis $analysis, AnalysisStatus $to, AnalysisStage $stage): Analysis
    {
        if (! $this->canTransition($analysis, $to)) {
            throw new LifecycleConflictException(
                sprintf('Cannot move analysis from %s to %s.', $analysis->status->value, $to->value),
                'INVALID_TRANSITION',
                [['from' => $analysis->status->value, 'to' => $to->value]],
            );
        }

        $from = $analysis->status;
        $analysis->status = $to;
        $analysis->current_stage = $stage;
        if ($to !== AnalysisStatus::FAILED) {
            $analysis->last_error_code = null;
            $analysis->last_error_message = null;
        }
        $analysis->save();

        Log::info('analysis.transition', ['analysis_id' => $analysis->public_id, 'from' => $from->value, 'to' => $to->value, 'stage' => $stage->value]);

        return $analysis;
    }

    /** Precondition guard for step endpoints: the analysis must have reached $required. */
    public function assertAtLeast(Analysis $analysis, AnalysisStatus $required, string $what): void
    {
        if ($analysis->status === AnalysisStatus::FAILED || ! $analysis->status->atLeast($required)) {
            throw new LifecycleConflictException(
                sprintf('%s requires the analysis to be at least %s; it is %s.', $what, $required->value, $analysis->status->value),
                'STEP_NOT_READY',
                [['required' => $required->value, 'current' => $analysis->status->value]],
            );
        }
    }

    /** Stages that belong to report generation; a failure here keeps the calculated evidence intact. */
    public const REPORT_STAGES = ['AI_SYNTHESIS', 'CLAIM_VALIDATION', 'REPORTING'];

    /** True when the report can be (re)built: analysed, or failed during the report phase with evidence on file. */
    public function canBuildReport(Analysis $analysis): bool
    {
        if ($analysis->status === AnalysisStatus::FAILED) {
            return in_array($analysis->current_stage->value, self::REPORT_STAGES, true) && $analysis->evidence()->exists();
        }

        return $analysis->status->atLeast(AnalysisStatus::ANALYZED);
    }

    public function assertCanBuildReport(Analysis $analysis): void
    {
        if (! $this->canBuildReport($analysis)) {
            throw new LifecycleConflictException(
                sprintf('Report generation requires the analysis to be at least ANALYZED; it is %s.', $analysis->status->value),
                'STEP_NOT_READY',
                [['required' => AnalysisStatus::ANALYZED->value, 'current' => $analysis->status->value]],
            );
        }
    }

    public function assertNotProcessing(Analysis $analysis): void
    {
        if ($analysis->status->isProcessing()) {
            throw new LifecycleConflictException('The analysis is currently processing; wait for it to finish.', 'ANALYSIS_BUSY');
        }
    }

    /** Opens a stage-history row (RUNNING) and returns it. */
    public function beginStage(Analysis $analysis, AnalysisStage $stage): AnalysisStageRecord
    {
        $attempt = (int) $analysis->stages()->where('stage', $stage->value)->max('attempt_no') + 1;

        return $analysis->stages()->create([
            'stage' => $stage,
            'status' => StageStatus::RUNNING,
            'attempt_no' => $attempt,
            'progress' => 0,
            'started_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $summary  counts and method names only, never rows */
    public function completeStage(AnalysisStageRecord $record, array $summary = []): void
    {
        $record->fill([
            'status' => StageStatus::SUCCEEDED,
            'progress' => 100,
            'finished_at' => now(),
            'duration_ms' => $record->started_at ? (int) $record->started_at->diffInMilliseconds(now()) : null,
            'result_summary' => $summary,
        ])->save();
    }

    public function failStage(AnalysisStageRecord $record, string $errorCode): void
    {
        $record->fill([
            'status' => StageStatus::FAILED,
            'finished_at' => now(),
            'duration_ms' => $record->started_at ? (int) $record->started_at->diffInMilliseconds(now()) : null,
            'error_code' => $errorCode,
        ])->save();
    }

    /** Marks the analysis FAILED with a safe error code/message (NFR-REL-001). */
    public function fail(Analysis $analysis, AnalysisStage $stage, string $code, string $message): Analysis
    {
        $analysis->last_error_code = $code;
        $analysis->last_error_message = mb_substr($message, 0, 500);

        return $this->transition($analysis, AnalysisStatus::FAILED, $stage);
    }
}
