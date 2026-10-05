<?php

namespace App\Services\Analytics;

use App\Enums\ColumnInferredType;
use App\Models\Analysis;
use Illuminate\Support\Facades\DB;

/**
 * Persists a PY-API-002 profile response as dataset_columns (derived metadata only).
 * Existing definitions are kept for columns whose header still exists.
 */
class ProfilePersister
{
    public function persist(Analysis $analysis, array $profile): void
    {
        $dataset = $profile['dataset'] ?? [];
        $columns = $profile['columns'] ?? [];

        DB::transaction(function () use ($analysis, $dataset, $columns): void {
            $seen = [];
            foreach ($columns as $index => $column) {
                $name = (string) ($column['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $seen[] = $name;
                $analysis->columns()->updateOrCreate(
                    ['source_name' => $name],
                    [
                        'position' => (int) ($column['position'] ?? $index),
                        'inferred_type' => self::inferredType($column['inferred_type'] ?? null),
                        'null_count' => (int) ($column['null_count'] ?? 0),
                        'null_rate' => (float) ($column['null_rate'] ?? 0),
                        'distinct_count' => isset($column['distinct_count']) ? (int) $column['distinct_count'] : null,
                        'profile_stats' => is_array($column['stats'] ?? null) ? $column['stats'] : [],
                    ],
                );
            }

            $stale = $analysis->columns()->whereNotIn('source_name', $seen)->get();
            foreach ($stale as $column) {
                $column->definition()->delete();
                $column->delete();
            }

            $analysis->forceFill([
                'row_count' => isset($dataset['rows']) ? (int) $dataset['rows'] : $analysis->row_count,
                'column_count' => isset($dataset['columns']) ? (int) $dataset['columns'] : count($seen),
                'completeness_pct' => isset($dataset['completeness']) ? round(((float) $dataset['completeness']) * 100, 2) : null,
            ])->save();
        });
    }

    public static function inferredType(?string $value): ColumnInferredType
    {
        return ColumnInferredType::tryFrom(strtoupper((string) $value)) ?? ColumnInferredType::UNKNOWN;
    }
}
