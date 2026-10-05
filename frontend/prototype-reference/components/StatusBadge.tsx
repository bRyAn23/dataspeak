import type { AnalysisStatus } from '../types';

const config: Record<AnalysisStatus, { label: string; className: string }> = {
  uploaded: { label: 'Uploaded', className: 'bg-blue-50 text-blue-700 border-blue-200' },
  profiled: { label: 'Profiled', className: 'bg-slate-100 text-slate-700 border-slate-200' },
  defined: { label: 'Defined', className: 'bg-violet-50 text-violet-700 border-violet-200' },
  analyzing: { label: 'Analyzing', className: 'bg-blue-50 text-blue-700 border-blue-200' },
  analyzed: { label: 'Analyzed', className: 'bg-blue-50 text-blue-700 border-blue-200' },
  generating: { label: 'Generating', className: 'bg-blue-50 text-blue-700 border-blue-200' },
  completed: { label: 'Completed', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  failed: { label: 'Failed', className: 'bg-red-50 text-red-700 border-red-200' },
};

export function StatusBadge({ status }: { status: AnalysisStatus }) {
  const { label, className } = config[status];
  return (
    <span
      className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border ${className}`}
    >
      {label}
    </span>
  );
}
