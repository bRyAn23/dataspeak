// Derived, label-free summary text for a profiled column.
import type { ColumnProfile } from '@/types/dataset.types';
import { formatInt, formatPct } from '@/utils/format';
import { t } from '@/i18n';

export function summarize(col: ColumnProfile): string {
  const s = col.stats ?? {};
  const n = (v: unknown) =>
    typeof v === 'number'
      ? new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(v)
      : String(v ?? '—');
  switch (col.inferred_type) {
    case 'NUMERIC':
    case 'INTEGER':
      return s.min !== undefined
        ? t('{min} to {max}, average {mean}', { min: n(s.min), max: n(s.max), mean: n(s.mean) })
        : '—';
    case 'DATE':
    case 'DATETIME':
      return s.min !== undefined ? t('{min} to {max}', { min: n(s.min), max: n(s.max) }) : '—';
    case 'CATEGORY':
      return typeof s.top_share === 'number'
        ? t('{n} groups, largest {share}', {
            n: formatInt(col.distinct_count),
            share: formatPct(s.top_share),
          })
        : t('{n} groups', { n: formatInt(col.distinct_count) });
    case 'BOOLEAN':
      return typeof s.true_rate === 'number'
        ? t('{pct} yes', { pct: formatPct(s.true_rate) })
        : '—';
    case 'IDENTIFIER':
      return t('{n} unique values', { n: formatInt(col.distinct_count) });
    default:
      return t('{n} distinct values', { n: formatInt(col.distinct_count) });
  }
}
