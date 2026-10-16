// FE-RPT-001 ExecutiveSummary: renders only validated narrative, plus the AI availability state.
import { Sparkles, Info } from 'lucide-react';
import type { Claim, Report } from '@/types/report.types';
import { ClaimLink } from './ClaimLink';
import { t } from '@/i18n';

const AI_NOTES: Record<Report['ai']['status'], string | null> = {
  ready: null,
  no_valid_claims:
    'The AI narrative did not pass verification, so it is not shown. The findings below are calculated directly from your data.',
  unavailable:
    'The AI narrative is not available right now. The findings below are calculated directly from your data.',
  disabled:
    'AI narrative is turned off in this deployment. The findings below are calculated directly from your data.',
};

export function ExecutiveSummary({ report }: { report: Report }) {
  const summaryClaims: Claim[] = report.claims.filter(
    (c) => c.kind === 'EXECUTIVE_SUMMARY' && c.status === 'SUPPORTED',
  );
  const note = AI_NOTES[report.ai.status];

  return (
    <section
      aria-labelledby="exec-summary"
      className="rounded-xl border border-slate-200 bg-white p-6"
    >
      <div className="flex items-center gap-2">
        <Sparkles size={18} className="text-brand-700" aria-hidden="true" />
        <h2 id="exec-summary" className="text-lg font-semibold text-slate-900">
          {t('Executive summary')}
        </h2>
      </div>
      {note && (
        <div
          className="mt-3 flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"
          role="status"
        >
          <Info size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
          <span>{t(note)}</span>
        </div>
      )}
      {summaryClaims.length > 0 ? (
        <p className="mt-4 text-[15px] leading-7 text-slate-800">
          {summaryClaims.map((c, i) => (
            <span key={c.id}>
              {c.statement}
              <sup className="ml-0.5 mr-1.5">
                {/* DEF-07: evidence links meet the 24px minimum target size (WCAG 2.5.8) */}
                <ClaimLink
                  analysisId={report.analysis_id}
                  claimId={c.id}
                  label={String(i + 1)}
                  compact
                />
              </sup>
            </span>
          ))}
        </p>
      ) : (
        report.ai.status === 'ready' && (
          <p className="mt-3 text-sm text-slate-500">
            {t('No summary statements passed verification.')}
          </p>
        )
      )}
      {report.ai.status === 'ready' && summaryClaims.length > 0 && (
        <p className="mt-4 text-xs text-slate-600">
          {t(
            'Every statement above was checked against the calculated results before being shown.',
          )}
        </p>
      )}
    </section>
  );
}
