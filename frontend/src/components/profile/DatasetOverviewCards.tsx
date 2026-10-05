// FE-PRO-001 DatasetOverviewCards: rows, columns, completeness and quality summary.
import type { ProfileResponse } from '@/types/dataset.types';
import { formatInt } from '@/utils/format';
import { t } from '@/i18n';

function Card({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="bg-white border border-slate-200 rounded-xl p-5">
      <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">{label}</p>
      <p className="mt-1.5 text-2xl font-semibold text-slate-900 tabular-nums">{value}</p>
      {sub && <p className="mt-0.5 text-sm text-slate-500">{sub}</p>}
    </div>
  );
}

export function DatasetOverviewCards({
  profile,
  issueCount,
}: {
  profile: ProfileResponse;
  issueCount: number;
}) {
  const completeness = profile.dataset.completeness_pct;
  return (
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <Card label={t('Rows')} value={formatInt(profile.dataset.rows)} />
      <Card label={t('Columns')} value={formatInt(profile.dataset.columns)} />
      <Card
        label={t('Completeness')}
        value={completeness === null ? '—' : `${completeness.toFixed(1)}%`}
        sub={t('Cells with a value')}
      />
      <Card
        label={t('Quality notes')}
        value={String(issueCount)}
        sub={issueCount === 0 ? t('No issues found') : t('See below')}
      />
    </div>
  );
}
