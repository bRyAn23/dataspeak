// FE-PG-008 ReportPage and FE-PG-009 traceability route: generate (RPT-API-001, async), poll, then show
// the executive report (RPT-API-002). Only backend-validated claims are rendered.
// Structure for business readers: key figures, one executive summary across all charts, then every
// chart with its own plain-language summary, other findings, and data notes.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Sparkles } from 'lucide-react';
import { useClaim, useGenerateReport, useReport } from '@/hooks/useAnalyses';
import { ApiError } from '@/services/apiClient';
import { useAnalysisGuard } from './useAnalysisGuard';
import { AnalysisStepper } from '@/components/layout/AnalysisStepper';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/layout/LoadingState';
import { ErrorState } from '@/components/layout/ErrorState';
import { Button } from '@/components/layout/Button';
import { AnalysisStatusPoller } from '@/components/analyze/AnalysisStatusPoller';
import { AnalysisFailurePanel } from '@/components/analyze/AnalysisFailurePanel';
import { ExecutiveSummary } from '@/components/report/ExecutiveSummary';
import { SummaryMetricCard } from '@/components/report/SummaryMetricCard';
import { ChartSummaryCard } from '@/components/report/ChartSummaryCard';
import { TraceabilityPanel } from '@/components/report/TraceabilityPanel';
import { ExportPdfAction } from '@/components/report/ExportPdfAction';
import { ClaimLink } from '@/components/report/ClaimLink';
import { formatDate } from '@/utils/format';
import { reportService } from '@/services/report.service';
import { dataNoteSentence } from '@/utils/evidenceWording';
import type { StatusResponse } from '@/types/analysis.types';
import { t, useI18n } from '@/i18n';
import { LOCALE_NAMES } from '@/lib/locale';

export function ReportPage() {
  const { id, query: analysis } = useAnalysisGuard(5);
  const { claimId } = useParams<{ claimId?: string }>();
  const navigate = useNavigate();
  const generate = useGenerateReport(id);
  const { locale } = useI18n();
  const autoRewritten = useRef<string | null>(null); // one automatic rewrite per analysis and language
  const [justFinished, setJustFinished] = useState<StatusResponse | null>(null);
  const a = analysis.data;
  const status = a?.status;
  const generating =
    status === 'REPORTING' || (generate.isSuccess && !justFinished && status !== 'COMPLETED');
  const report = useReport(id, status === 'COMPLETED' && !generating);
  const claim = useClaim(id, claimId);

  const onStopped = useCallback(
    (s: StatusResponse) => {
      setJustFinished(s);
      analysis.refetch();
      report.refetch();
    },
    [analysis, report],
  );

  const start = () => {
    setJustFinished(null);
    generate.mutate();
  };

  const r = report.data;

  // FEAT-I18N-003: when the interface language differs from the report's, the verified statements are translated
  // (never re-interpreted) once per analysis and language; the report stays visible meanwhile.
  const [translating, setTranslating] = useState<'idle' | 'running' | 'failed' | 'partial'>('idle');
  useEffect(() => {
    if (!r || !r.language || r.language === locale || r.display_language !== locale) return;
    if (r.translation?.complete !== false) return;
    if (status !== 'COMPLETED' || generating) return;
    const key = `${id}:${locale}`;
    if (autoRewritten.current === key) return;
    autoRewritten.current = key;
    setTranslating('running');
    // Reports are pre-translated in the background right after they are written; usually the translation is
    // already there or arrives within seconds, so the page polls instead of starting a second one.
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let attempts = 0;
    const finish = (complete: boolean | undefined) => setTranslating(complete ? 'idle' : 'partial');
    const check = async (): Promise<void> => {
      if (cancelled) return;
      attempts += 1;
      const res = await report.refetch();
      const tr = res.data?.translation;
      if (cancelled) return;
      if (tr?.complete) {
        finish(true);
      } else if (attempts >= 90) {
        finish(false);
      } else if (tr?.in_progress) {
        // A background job that had to retry (model busy) can take a few minutes: poll fast first, then every 5 s.
        timer = setTimeout(() => void check(), attempts < 10 ? 2000 : 5000);
      } else {
        await request();
      }
    };
    const request = async (): Promise<void> => {
      try {
        const out = await reportService.translate(id, locale);
        if (cancelled) return;
        if (out.status === 'in_progress') timer = setTimeout(() => void check(), 1500);
        else {
          const again = await report.refetch();
          if (!cancelled) finish(again.data?.translation?.complete);
        }
      } catch {
        if (!cancelled) setTranslating('failed');
      }
    };
    if (r.translation?.in_progress) timer = setTimeout(() => void check(), 1500);
    else void request();
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [r, locale, status, generating, id, report]);
  const insightsByRank = useMemo(
    () => new Map((r?.insights ?? []).map((ins) => [ins.rank, ins])),
    [r],
  );
  const summaryTexts = useMemo(
    () =>
      new Set(
        (r?.claims ?? [])
          .filter((c) => c.kind === 'EXECUTIVE_SUMMARY')
          .map((c) => c.statement.trim()),
      ),
    [r],
  );
  const chartTextIds = useMemo(
    () =>
      new Set(
        (r?.visualizations ?? []).flatMap((v) =>
          (v.summary ?? []).map((s) => s.claim_id).filter(Boolean),
        ),
      ),
    [r],
  );
  const uncharted = (r?.insights ?? []).filter(
    (ins) => !(r?.visualizations ?? []).some((v) => v.insight_rank === ins.rank),
  );
  const otherFindings = (r?.claims ?? []).filter(
    (c) =>
      c.kind === 'FINDING' &&
      c.status === 'SUPPORTED' &&
      !summaryTexts.has(c.statement.trim()) &&
      !chartTextIds.has(c.id),
  );
  const caveats = (r?.claims ?? []).filter((c) => c.kind === 'CAVEAT' && c.status === 'SUPPORTED');
  const closePanel = useCallback(() => navigate(`/analyses/${id}/report`), [navigate, id]);

  return (
    <>
      <AnalysisStepper current={5} analysisId={id} status={status} />
      <PageHeader
        title={a?.name ?? t('Executive report')}
        subtitle={
          r
            ? t('Generated {when} from {file}', {
                when: formatDate(r.generated_at, true),
                file: r.dataset_name ?? t('your dataset'),
              })
            : t('Findings, verified narrative and charts.')
        }
        actions={r && id ? <ExportPdfAction analysisId={id} /> : undefined}
      />
      {analysis.isLoading && <LoadingState />}
      {analysis.isError && <ErrorState error={analysis.error} onRetry={() => analysis.refetch()} />}
      {generate.isError ? (
        <ErrorState
          error={generate.error}
          onRetry={start}
          title={t('The report could not be started')}
        />
      ) : null}

      {a && status === 'ANALYZED' && !generating && (
        <div className="rounded-xl border border-slate-200 bg-white p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div className="text-sm text-slate-600">
            <p className="font-medium text-slate-800">{t('Write the executive report')}</p>
            <p>
              {t(
                'The AI interprets the calculated evidence; every statement is verified before it is shown.',
              )}
            </p>
          </div>
          <Button
            onClick={start}
            loading={generate.isPending}
            icon={<Sparkles size={16} aria-hidden="true" />}
          >
            {t('Generate report')}
          </Button>
        </div>
      )}

      {a && generating && (
        <AnalysisStatusPoller analysisId={id} mode="report" onStopped={onStopped} />
      )}

      {a && status === 'FAILED' && !generating && (
        <AnalysisFailurePanel
          error={a.last_error}
          canRetry
          onRetry={start}
          retrying={generate.isPending}
          analysisId={id}
        />
      )}

      {status === 'COMPLETED' && report.isLoading && <LoadingState label={t('Loading report…')} />}
      {report.isError &&
        !generating &&
        !(report.error instanceof ApiError && report.error.code === 'STEP_NOT_READY') && (
          <ErrorState error={report.error} onRetry={() => report.refetch()} />
        )}

      {r && (
        <div className="space-y-6">
          {r.summary_metrics.length > 0 && (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              {r.summary_metrics.map((m) => (
                <SummaryMetricCard key={m.evidence_key} metric={m} />
              ))}
            </div>
          )}

          {r.language && r.language !== locale && translating !== 'idle' && (
            <p
              role="status"
              className={`rounded-xl border p-3 text-sm ${translating === 'running' ? 'border-brand-100 bg-brand-50 text-slate-800' : 'border-amber-200 bg-amber-50 text-amber-900'}`}
            >
              {translating === 'running'
                ? t('Translating the summary into {language}…', { language: LOCALE_NAMES[locale] })
                : t(
                    'Some statements are shown in {language} because they could not be translated yet.',
                    {
                      language: LOCALE_NAMES[r.language],
                    },
                  )}
            </p>
          )}
          <ExecutiveSummary report={r} />

          <section aria-labelledby="charts-h" className="space-y-4">
            <h2 id="charts-h" className="text-lg font-semibold text-slate-900">
              {t('Charts and what they show')}
            </h2>
            {r.visualizations.length === 0 && (
              <p className="text-sm text-slate-500">
                {t('No charts were produced for this dataset.')}
              </p>
            )}
            {r.visualizations.map((v) => (
              <ChartSummaryCard
                key={v.id}
                analysisId={id}
                chart={v}
                theme={r.chart_theme}
                insight={v.insight_rank !== null ? insightsByRank.get(v.insight_rank) : undefined}
              />
            ))}
          </section>

          {(uncharted.length > 0 || otherFindings.length > 0) && (
            <section
              aria-labelledby="other-h"
              className="rounded-xl border border-slate-200 bg-white p-6"
            >
              <h2 id="other-h" className="text-base font-semibold text-slate-900">
                {t('Also worth knowing')}
              </h2>
              <ul className="mt-3 space-y-2 text-sm text-slate-800">
                {otherFindings.map((c) => (
                  <li key={c.id}>
                    {c.statement} <ClaimLink analysisId={id} claimId={c.id} />
                  </li>
                ))}
                {uncharted.map((ins) => (
                  <li key={`ins-${ins.rank}`}>{ins.headline}</li>
                ))}
              </ul>
            </section>
          )}

          {(caveats.length > 0 || r.data_notes.length > 0) && (
            <section
              aria-labelledby="notes-h"
              className="rounded-xl border border-amber-200 bg-amber-50 p-6"
            >
              <h2 id="notes-h" className="text-base font-semibold text-amber-900">
                {t('Data notes and limitations')}
              </h2>
              <ul className="mt-3 space-y-2 text-sm text-amber-900">
                {caveats.map((c) => (
                  <li key={c.id}>
                    {c.statement} <ClaimLink analysisId={id} claimId={c.id} />
                  </li>
                ))}
                {r.data_notes
                  .filter(
                    (n) =>
                      n.severity !== 'ACCEPTABLE' ||
                      ['duplicate_rows', 'completeness'].includes(n.metric),
                  )
                  .map((n, i) => (
                    <li key={i}>
                      {dataNoteSentence(
                        (n.labels ?? n.columns).join(', ') || t('The data'),
                        n.metric,
                        n.display_value,
                      )}
                    </li>
                  ))}
              </ul>
            </section>
          )}

          <div className="flex items-center justify-between text-sm text-slate-500">
            <span>
              {r.claims_supported === r.claims_total
                ? t('All {total} summary statements were checked against the calculated results.', {
                    total: r.claims_total,
                  })
                : t(
                    '{supported} of {total} summary statements passed the checks; the rest are not shown.',
                    {
                      supported: r.claims_supported,
                      total: r.claims_total,
                    },
                  )}
            </span>
            <Button variant="secondary" onClick={start} loading={generate.isPending}>
              {t('Rewrite summary')}
            </Button>
          </div>
        </div>
      )}

      {claimId && (
        <TraceabilityPanel
          claim={claim.data}
          loading={claim.isLoading}
          error={claim.error}
          onClose={closePanel}
          onRetry={() => claim.refetch()}
        />
      )}
    </>
  );
}
