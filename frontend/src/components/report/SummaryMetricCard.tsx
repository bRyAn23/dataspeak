// FE-RPT-003 SummaryMetricCard: a deterministic KPI with its unit from the backend.
import type { SummaryMetric } from '@/types/report.types';

export function SummaryMetricCard({ metric }: { metric: SummaryMetric }) {
  const showUnit = metric.unit && !metric.display_value.includes(metric.unit);
  return (
    <div className="bg-white border border-slate-200 rounded-xl p-5">
      <p className="text-xs font-medium text-slate-500 uppercase tracking-wide break-words">
        {metric.label}
      </p>
      <p className="mt-1.5 text-2xl font-semibold text-slate-900 tabular-nums break-words">
        {metric.display_value}
        {showUnit && (
          <span className="ml-1 text-base font-normal text-slate-500">{metric.unit}</span>
        )}
      </p>
    </div>
  );
}
