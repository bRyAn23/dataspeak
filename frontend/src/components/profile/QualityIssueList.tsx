// FE-PRO-003 QualityIssueList: warnings and quality findings derived from the stored profile.
import { AlertTriangle, Info } from 'lucide-react';
import type { QualityIssue } from '@/utils/quality';
import { t } from '@/i18n';

export function QualityIssueList({ issues }: { issues: QualityIssue[] }) {
  if (issues.length === 0) {
    return (
      <div className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
        <Info size={16} aria-hidden="true" />
        {t('No data quality issues were found.')}
      </div>
    );
  }
  return (
    <ul className="space-y-2">
      {issues.map((i, idx) => (
        <li
          key={idx}
          className={`flex items-start gap-3 rounded-xl border p-4 text-sm ${i.severity === 'insufficient' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800'}`}
        >
          <AlertTriangle size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
          <span>
            <span className="font-medium">{i.column}</span>: {i.message}
          </span>
        </li>
      ))}
    </ul>
  );
}
