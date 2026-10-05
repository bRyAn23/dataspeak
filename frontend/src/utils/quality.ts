// Quality issues derived from the stored profile (counts and rates only).
import type { ColumnProfile } from '@/types/dataset.types';
import { formatPct } from '@/utils/format';
import { t } from '@/i18n';

export interface QualityIssue {
  severity: 'warning' | 'insufficient';
  column: string;
  message: string;
}

export function deriveIssues(columns: ColumnProfile[], rows: number | null): QualityIssue[] {
  const issues: QualityIssue[] = [];
  for (const c of columns) {
    if (c.null_rate >= 0.9)
      issues.push({
        severity: 'insufficient',
        column: c.name,
        message: t('{pct} of values are missing; this column cannot be analysed.', {
          pct: formatPct(c.null_rate),
        }),
      });
    else if (c.null_rate >= 0.1)
      issues.push({
        severity: 'warning',
        column: c.name,
        message: t('{pct} of values are missing; statistics use the remaining rows.', {
          pct: formatPct(c.null_rate),
        }),
      });
    if (
      (rows ?? 0) > 1 &&
      c.distinct_count !== null &&
      c.distinct_count <= 1 &&
      c.null_count < (rows ?? 0)
    ) {
      issues.push({
        severity: 'warning',
        column: c.name,
        message: t('Every row has the same value.'),
      });
    }
    if (c.inferred_type === 'UNKNOWN')
      issues.push({
        severity: 'insufficient',
        column: c.name,
        message: t('No usable values were detected.'),
      });
  }
  return issues;
}
