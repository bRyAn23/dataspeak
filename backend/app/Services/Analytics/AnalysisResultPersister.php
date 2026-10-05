<?php

namespace App\Services\Analytics;

use App\Enums\AnalysisFamily;
use App\Enums\ChartType;
use App\Enums\InsightType;
use App\Enums\QualityStatus;
use App\Models\Analysis;
use Illuminate\Support\Facades\DB;

/**
 * Persists PY-API-004/005/006 results as evidence, insights and visualizations.
 * Replaces any previous run's results. Enum strings are upper-cased into dictionary values.
 */
class AnalysisResultPersister
{
    public function clear(Analysis $analysis): void
    {
        DB::transaction(function () use ($analysis): void {
            $claimIds = $analysis->claims()->pluck('id');
            DB::table('claim_evidence')->whereIn('claim_id', $claimIds)->delete();
            $analysis->claims()->delete();
            $analysis->report()->delete();
            $analysis->visualizations()->delete();
            $analysis->insights()->delete();
            $analysis->evidence()->delete();
        });
    }

    /** @return array<string, int> evidence_key => id */
    public function persistEvidence(Analysis $analysis, array $evidenceList): array
    {
        $columnIds = $analysis->columns()->pluck('id', 'source_name')->all();
        $map = [];

        DB::transaction(function () use ($analysis, $evidenceList, $columnIds, &$map): void {
            foreach ($evidenceList as $item) {
                $key = (string) ($item['evidence_id'] ?? '');
                if ($key === '' || isset($map[$key])) {
                    continue;
                }
                $row = $analysis->evidence()->create([
                    'evidence_key' => mb_substr($key, 0, 64),
                    'analysis_family' => AnalysisFamily::tryFrom(strtoupper((string) ($item['analysis_family'] ?? ''))) ?? AnalysisFamily::DESCRIPTIVE,
                    'metric' => mb_substr((string) ($item['metric'] ?? 'unknown'), 0, 64),
                    'method' => mb_substr((string) ($item['method'] ?? 'unknown'), 0, 64),
                    'value' => is_numeric($item['value'] ?? null) ? $item['value'] : null,
                    'display_value' => isset($item['display_value']) ? mb_substr((string) $item['display_value'], 0, 64) : null,
                    'metric_column_id' => $columnIds[$item['metric_column'] ?? ''] ?? null,
                    'dimension_column_id' => $columnIds[$item['dimension_column'] ?? ''] ?? null,
                    'source_columns' => array_values(array_map('strval', (array) ($item['source_columns'] ?? []))),
                    'parameters' => is_array($item['parameters'] ?? null) ? $item['parameters'] : [],
                    'quality_status' => QualityStatus::tryFrom(strtoupper((string) ($item['quality']['status'] ?? ''))) ?? QualityStatus::ACCEPTABLE,
                    'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : null,
                ]);
                $map[$key] = $row->id;
            }
        });

        return $map;
    }

    /** @param  array<string, int>  $evidenceIds */
    public function persistInsights(Analysis $analysis, array $insights, array $evidenceIds): void
    {
        DB::transaction(function () use ($analysis, $insights, $evidenceIds): void {
            $rank = 0;
            foreach ($insights as $item) {
                $primary = (string) ($item['primary_evidence_id'] ?? ($item['evidence_ids'][0] ?? ''));
                if (! isset($evidenceIds[$primary])) {
                    continue; // every insight must reference persisted evidence
                }
                $rank++;
                $analysis->insights()->create([
                    'evidence_id' => $evidenceIds[$primary],
                    'insight_type' => InsightType::tryFrom(strtoupper((string) ($item['insight_type'] ?? ''))) ?? InsightType::DISTRIBUTION,
                    'headline' => mb_substr((string) ($item['headline'] ?? ''), 0, 255),
                    'description' => isset($item['description']) ? mb_substr((string) $item['description'], 0, 1000) : null,
                    'score' => (float) ($item['score'] ?? 0),
                    'rank' => (int) ($item['rank'] ?? $rank),
                    'is_selected' => true,
                    'supporting_evidence_keys' => array_values(array_map('strval', (array) ($item['evidence_ids'] ?? []))),
                ]);
            }
        });
    }

    public function persistVisualizations(Analysis $analysis, array $visualizations): void
    {
        $insightIdsByRank = $analysis->insights()->pluck('id', 'rank')->all();

        DB::transaction(function () use ($analysis, $visualizations, $insightIdsByRank): void {
            foreach (array_values($visualizations) as $position => $item) {
                $analysis->visualizations()->create([
                    'insight_id' => $insightIdsByRank[$item['insight_rank'] ?? -1] ?? null,
                    'chart_type' => ChartType::tryFrom(strtoupper((string) ($item['type'] ?? ''))) ?? ChartType::TABLE,
                    'title' => mb_substr((string) ($item['title'] ?? 'Untitled'), 0, 255),
                    'spec' => [
                        'x' => $item['x'] ?? null,
                        'y' => $item['y'] ?? null,
                        'unit_missing' => (bool) ($item['unit_missing'] ?? empty($item['y']['unit'] ?? null)),
                        'series' => is_array($item['series'] ?? null) ? $item['series'] : [],
                    ],
                    'evidence_keys' => array_values(array_map('strval', (array) ($item['evidence_ids'] ?? []))),
                    'position' => $position,
                ]);
            }
        });
    }
}
