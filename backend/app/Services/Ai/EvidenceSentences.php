<?php

namespace App\Services\Ai;

use App\Models\Evidence;
use App\Support\Locale;

/**
 * Software-authored business sentences for evidence records, with the record's own placeholders already
 * in the right slots, in the report language (lang/{locale}/sentences.php). The model composes the narrative
 * from these sentences instead of arranging placeholders itself, which removes the most common semantic
 * mistakes of small models. Verbs that depend on the sign of a value are chosen here, by code.
 */
class EvidenceSentences
{
    /** Returns null when the sentence would need a field this evidence cannot resolve. */
    public static function for(Evidence $e, PlaceholderResolver $resolver, ?string $locale = null): ?string
    {
        $locale = Locale::normalize($locale ?? $resolver->locale());
        $id = $e->evidence_key;
        $has = fn (string ...$fields) => array_reduce($fields, fn ($ok, $f) => $ok && $resolver->resolve($id, $f) !== null, true);
        $vars = [
            'group' => '{'.$id.'.group}', 'label' => '{'.$id.'.label}', 'value' => '{'.$id.'.display_value}',
            'dimension' => '{'.$id.'.dimension}', 'period' => '{'.$id.'.period}', 'from' => '{'.$id.'.period_from}', 'to' => '{'.$id.'.period_to}',
        ];
        $t = fn (string $key, array $extra = []) => __('sentences.'.$key, $extra + $vars, $locale);
        $value = $e->value !== null ? (float) $e->value : null;
        $dir = $value === null || $value >= 0 ? 'up' : 'down';

        $sentence = match ($e->metric) {
            'top_group_share' => $has('group', 'label', 'dimension') ? $t('top_group_share') : null,
            'top_category_share' => $has('group', 'label') ? $t('top_category_share') : null,
            'top_bottom_mean_difference' => $has('group', 'label', 'dimension') ? $t('top_bottom_mean_difference') : null,
            'latest_period_change' => $has('period_from', 'period_to', 'label') ? $t('latest_period_change_'.$dir) : null,
            'average_period_growth' => $has('period', 'label')
                ? $t('average_period_growth_'.$dir, ['range' => $has('period_from', 'period_to') ? $t('between') : '']) : null,
            'total_change' => $has('label') ? $t('total_change_'.$dir) : null,
            'trend_slope' => $has('label') ? $t('trend_slope_'.$dir) : null,
            'spearman_correlation' => $has('label', 'dimension') ? $t($dir === 'up' ? 'spearman_together' : 'spearman_opposite') : null,
            'cramers_v' => $has('label', 'dimension') ? $t('cramers_v') : null,
            'group_difference_test' => $has('dimension', 'label') ? $t('group_difference_test') : null,
            'sum', 'mean', 'median', 'min', 'max', 'null_rate', 'outlier_count', 'constant_column' => $has('label') ? $t($e->metric) : null,
            'row_count', 'completeness', 'duplicate_rows' => $t($e->metric),
            default => null,
        };

        return $e->display_value === null ? null : $sentence;
    }
}
