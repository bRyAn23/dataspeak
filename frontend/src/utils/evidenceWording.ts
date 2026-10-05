// Business wording for evidence metrics and methods. Technical names never reach the reader directly.

import { t } from '@/i18n';

export const METRIC_LABELS: Record<string, string> = {
  row_count: 'Number of records',
  count: 'Number of values',
  sum: 'Total',
  mean: 'Average',
  median: 'Typical value',
  min: 'Lowest value',
  max: 'Highest value',
  std: 'Spread of values',
  distribution: 'How values are spread',
  distinct_count: 'Number of groups',
  top_category_share: 'Share held by the largest group',
  period_series: 'Values over time',
  latest_period_change: 'Change in the latest period',
  average_period_growth: 'Average change per period',
  total_change: 'Change from first to last period',
  trend_slope: 'Overall direction over time',
  group_aggregates: 'Totals by group',
  top_group_share: 'Share held by the leading group',
  top_bottom_mean_difference: 'Gap between highest and lowest group',
  group_difference_test: 'Check that groups really differ',
  spearman_correlation: 'How closely two measures move together',
  cramers_v: 'How strongly two groupings are linked',
  completeness: 'Completeness of the data',
  duplicate_rows: 'Duplicate records',
  null_rate: 'Missing values',
  constant_column: 'Single repeated value',
  type_parse_failures: 'Values that did not match the detected type',
  outlier_count: 'Unusual values',
};

export const METHOD_LABELS: Record<string, string> = {
  count_rows: 'Counted the records',
  count_non_null: 'Counted the values present',
  arithmetic_sum: 'Added up all values',
  arithmetic_mean: 'Averaged all values',
  median: 'Took the middle value',
  minimum: 'Took the lowest value',
  maximum: 'Took the highest value',
  sample_standard_deviation: 'Measured how far values spread from the average',
  histogram_equal_width: 'Grouped values into equal ranges',
  count_distinct: 'Counted the different groups',
  frequency_share: 'Compared group counts to the total',
  period_aggregation_sum: 'Added up values for each period',
  period_aggregation_mean: 'Averaged values for each period',
  period_over_period_change: 'Compared the latest period with the one before',
  geometric_mean_growth: 'Averaged the period-to-period changes',
  first_to_last_change: 'Compared the last period with the first',
  ols_linear_trend: 'Fitted a straight trend line through the periods',
  group_by_aggregate: 'Added up and averaged values for each group',
  share_of_total: 'Compared each group total with the overall total',
  relative_mean_difference: 'Compared the highest and lowest group averages',
  welch_t_test: 'Statistical check for a real difference between two groups',
  mann_whitney_u: 'Statistical check for a real difference between two groups',
  one_way_anova: 'Statistical check for a real difference between groups',
  kruskal_wallis: 'Statistical check for a real difference between groups',
  none_applicable: 'No statistical check was possible with these group sizes',
  spearman_rank_correlation: 'Measured how consistently the two measures rise and fall together',
  chi_square_independence: 'Checked whether the two groupings are related',
  non_null_cell_share: 'Share of cells that have a value',
  exact_duplicate_count: 'Counted identical records',
  missing_value_share: 'Share of records with no value',
  distinct_count: 'Counted the different values',
  type_inference_mismatch: 'Counted values that did not fit the detected type',
  iqr_fence: 'Flagged values far outside the usual range',
};

export function metricLabel(metric: string): string {
  return METRIC_LABELS[metric] ? t(METRIC_LABELS[metric]) : metric.replace(/_/g, ' ');
}

export function methodLabel(method: string): string {
  return METHOD_LABELS[method] ? t(METHOD_LABELS[method]) : method.replace(/_/g, ' ');
}

/** One business sentence for a data note. */
export function dataNoteSentence(
  label: string,
  metric: string,
  displayValue: string | null,
): string {
  const v = displayValue ?? '';
  switch (metric) {
    case 'null_rate':
      return t('{label}: {v} of values are missing, so figures for it use the remaining records.', {
        label,
        v,
      });
    case 'outlier_count':
      return t(
        '{label}: {v} unusually high or low values were found; they may pull averages up or down.',
        { label, v },
      );
    case 'duplicate_rows':
      return t('{v} duplicate records were found and are included in the totals.', { v });
    case 'completeness':
      return t('{v} of all cells have a value.', { v });
    case 'constant_column':
      return t('{label} has the same value in every record, so it cannot be compared.', { label });
    case 'type_parse_failures':
      return t('{label}: {v} values did not match the detected type and were left out.', {
        label,
        v,
      });
    default:
      return `${label}: ${metricLabel(metric).toLowerCase()}${v ? ` ${v}` : ''}.`;
  }
}
