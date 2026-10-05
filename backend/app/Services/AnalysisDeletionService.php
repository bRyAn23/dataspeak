<?php

namespace App\Services;

use App\Models\Analysis;
use App\Services\Analytics\ProgressStore;
use App\Services\Uploads\TransientUploadStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ANL-API-004: removes an analysis and all derived records explicitly, in dependency order,
 * inside one transaction. Foreign keys are RESTRICT by design (data dictionary Decisions sheet).
 */
class AnalysisDeletionService
{
    public function __construct(
        private readonly TransientUploadStore $uploads,
        private readonly ProgressStore $progress,
    ) {}

    public function delete(Analysis $analysis): void
    {
        $publicId = $analysis->public_id;

        DB::transaction(function () use ($analysis): void {
            $claimIds = $analysis->claims()->pluck('id');
            DB::table('claim_evidence')->whereIn('claim_id', $claimIds)->delete();
            $analysis->claims()->delete();
            $analysis->report()->delete();
            $analysis->visualizations()->delete();
            $analysis->insights()->delete();
            $analysis->evidence()->delete();
            $columnIds = $analysis->columns()->pluck('id');
            DB::table('field_definitions')->whereIn('dataset_column_id', $columnIds)->delete();
            $analysis->columns()->delete();
            $analysis->aiGenerations()->delete();
            $analysis->stages()->delete();
            $analysis->delete();
        });

        $this->uploads->delete($analysis, 'analysis_deleted');
        $this->progress->clear($analysis);

        Log::info('analysis.deleted', ['analysis_id' => $publicId]);
    }
}
