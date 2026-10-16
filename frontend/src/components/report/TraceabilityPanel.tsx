// FE-RPT-006 TraceabilityPanel: claim -> evidence -> how it was calculated, in business language (NFR-TRC-001).
// Technical identifiers stay available under a collapsed "Technical details" section for auditors.
import { useEffect, useRef } from 'react';
import { X } from 'lucide-react';
import type { Claim, Evidence } from '@/types/report.types';
import { CLAIM_STATUS_LABELS, QUALITY_LABELS, describeReason } from '@/utils/statusLabels';
import { metricLabel, methodLabel } from '@/utils/evidenceWording';
import { LoadingState } from '@/components/layout/LoadingState';
import { ErrorState } from '@/components/layout/ErrorState';
import { t } from '@/i18n';

interface Props {
  claim: Claim | undefined;
  loading: boolean;
  error: unknown;
  onClose: () => void;
  onRetry: () => void;
}

const INFORMATIONAL = new Set([
  'CITATION_COMPLETED',
  'REPAIRED',
  'RECLASSIFIED_AS_CAVEAT',
  'PLACED_BY_SOFTWARE',
  'NOT_IN_NARRATIVE',
]);

function subject(e: Evidence): string {
  const what = e.label ?? e.source_columns[0] ?? t('the data');
  const by = e.dimension ? t(' by {label}', { label: e.dimension }) : '';
  const period =
    e.period_from && e.period_to
      ? t(', {from} to {to}', { from: e.period_from, to: e.period_to })
      : '';
  return `${what}${by}${period}`;
}

export function TraceabilityPanel({ claim, loading, error, onClose, onRetry }: Props) {
  const closeRef = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    closeRef.current?.focus();
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const issues = (claim?.validation.reasons ?? []).filter(
    (r) => !INFORMATIONAL.has(r.split(':')[0]),
  );

  return (
    <div className="fixed inset-0 z-40 flex justify-end">
      <div className="absolute inset-0 bg-black/30" onClick={onClose} aria-hidden="true" />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="trace-title"
        className="relative h-full w-full max-w-lg overflow-y-auto bg-white shadow-xl"
      >
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <h2 id="trace-title" className="text-base font-semibold text-slate-900">
            {t('How we know')}
          </h2>
          <button
            ref={closeRef}
            type="button"
            onClick={onClose}
            className="rounded-lg p-1.5 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-brand-700"
            aria-label={t('Close')}
          >
            <X size={18} aria-hidden="true" />
          </button>
        </div>
        <div className="px-6 py-5 space-y-6 text-sm">
          {loading && <LoadingState />}
          {error ? <ErrorState error={error} onRetry={onRetry} /> : null}
          {claim && (
            <>
              <section>
                <p className="text-slate-900 leading-6">{claim.statement}</p>
                <p className="mt-2">
                  <span
                    className={`rounded border px-1.5 py-0.5 text-xs ${claim.status === 'SUPPORTED' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700'}`}
                  >
                    {claim.status === 'SUPPORTED'
                      ? t('Checked against the calculated results')
                      : t(CLAIM_STATUS_LABELS[claim.status])}
                  </span>
                </p>
                {issues.length > 0 && (
                  <ul className="mt-2 list-disc pl-5 text-xs text-amber-800">
                    {issues.map((r) => (
                      <li key={r}>{describeReason(r)}</li>
                    ))}
                  </ul>
                )}
              </section>
              <section>
                <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {t('The numbers behind it')}
                </h3>
                <ul className="mt-2 space-y-3">
                  {(claim.evidence ?? []).map((e) => (
                    <li key={e.evidence_id} className="rounded-lg border border-slate-200 p-4">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium text-slate-800">{metricLabel(e.metric)}</p>
                          <p className="text-xs text-slate-500">{subject(e)}</p>
                        </div>
                        <span className="shrink-0 text-base font-semibold tabular-nums text-slate-900">
                          {e.display_value ?? e.value ?? '—'}
                        </span>
                      </div>
                      <p className="mt-2 text-xs text-slate-600">{methodLabel(e.method)}.</p>
                      {e.quality.status !== 'ACCEPTABLE' && (
                        <p className="mt-1 text-xs text-amber-700">
                          {t('Reliability: {level}.', {
                            level: t(QUALITY_LABELS[e.quality.status]),
                          })}
                        </p>
                      )}
                    </li>
                  ))}
                </ul>
              </section>
              <details className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                <summary className="cursor-pointer font-medium text-slate-700">
                  {t('Technical details')}
                </summary>
                <dl className="mt-2 grid grid-cols-3 gap-x-3 gap-y-1">
                  <dt className="text-slate-600">{t('Statement template')}</dt>
                  <dd className="col-span-2 break-words font-mono">{claim.statement_template}</dd>
                  <dt className="text-slate-600">{t('Check')}</dt>
                  <dd className="col-span-2">
                    {claim.validation.method ?? '—'}
                    {claim.validation.reasons.length
                      ? ` (${claim.validation.reasons.join(', ')})`
                      : ''}
                  </dd>
                  {claim.generation && (
                    <>
                      <dt className="text-slate-600">{t('Written by')}</dt>
                      <dd className="col-span-2">
                        {t('AI model, prompt version {prompt}', {
                          prompt: claim.generation.prompt_version ?? '—',
                        })}
                      </dd>
                    </>
                  )}
                  {(claim.evidence ?? []).map((e) => (
                    <div
                      key={e.evidence_id}
                      className="col-span-3 mt-1 border-t border-slate-200 pt-1"
                    >
                      <span className="font-mono">{e.evidence_id}</span> · {e.metric} · {e.method} ·{' '}
                      {e.source_columns.join(', ') || t('whole dataset')}
                      {e.parameters && Object.keys(e.parameters).length > 0
                        ? ` · ${JSON.stringify(e.parameters)}`
                        : ''}
                    </div>
                  ))}
                </dl>
              </details>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
