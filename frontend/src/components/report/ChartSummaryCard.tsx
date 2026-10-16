// A chart with its own plain-language summary beneath it (what this chart shows).
import type { Insight, Visualization } from '@/types/report.types';
import { INSIGHT_TYPE_LABELS } from '@/utils/statusLabels';
import { ChartRenderer } from './ChartRenderer';
import { ClaimLink } from './ClaimLink';
import { t } from '@/i18n';

interface Props {
  analysisId: string;
  chart: Visualization;
  insight?: Insight;
  /** Colour theme of the report (FEAT-VIS-004). */
  theme?: string | null;
}

export function ChartSummaryCard({ analysisId, chart, insight, theme }: Props) {
  const summary = chart.summary ?? [];
  return (
    <article
      className="rounded-xl border border-slate-200 bg-white p-6"
      aria-labelledby={`chart-${chart.id}`}
    >
      {insight && (
        <p className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">
          {t('Finding #{rank}', { rank: insight.rank })} ·{' '}
          {t(INSIGHT_TYPE_LABELS[insight.insight_type])}
        </p>
      )}
      <h3 id={`chart-${chart.id}`} className="sr-only">
        {chart.title}
      </h3>
      <ChartRenderer spec={chart} theme={theme} />
      <div className="mt-4 rounded-lg bg-slate-50 p-4">
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
          {t('What this chart shows')}
        </p>
        {summary.length === 0 ? (
          <p className="mt-1 text-sm text-slate-500">
            {t('No summary is available for this chart.')}
          </p>
        ) : (
          <ul className="mt-1 space-y-1.5 text-sm leading-6 text-slate-800">
            {summary.map((item, i) => (
              <li key={i}>
                {item.text}
                {item.claim_id ? (
                  <span className="ml-2">
                    <ClaimLink analysisId={analysisId} claimId={item.claim_id} />
                  </span>
                ) : (
                  <span className="ml-2 text-xs text-slate-600">{t('calculated')}</span>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </article>
  );
}
