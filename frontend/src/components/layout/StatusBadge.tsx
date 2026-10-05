// FE-CMP-005 StatusBadge: user-friendly analysis status, never the raw enum.
import type { AnalysisStatus } from '@/types/analysis.types';
import { STATUS_LABELS, STATUS_TONE } from '@/utils/statusLabels';
import { cn } from '@/lib/cn';
import { t } from '@/i18n';

const TONE: Record<string, string> = {
  neutral: 'bg-slate-100 text-slate-700 border-slate-200',
  info: 'bg-blue-50 text-blue-700 border-blue-200',
  accent: 'bg-violet-50 text-violet-700 border-violet-200',
  success: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  danger: 'bg-red-50 text-red-700 border-red-200',
};

export function StatusBadge({ status }: { status: AnalysisStatus }) {
  return (
    <span
      className={cn(
        'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border',
        TONE[STATUS_TONE[status]],
      )}
    >
      {t(STATUS_LABELS[status])}
    </span>
  );
}
