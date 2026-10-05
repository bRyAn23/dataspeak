// FE-PG-007 AnalysisRunPage: one click runs the whole pipeline (RUN-API-001), the page follows every stage
// through RUN-API-002 polling, celebrates completion and opens the report.
import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { useGenerateReport, useRunAnalysis } from '@/hooks/useAnalyses';
import { useAnalysisGuard } from './useAnalysisGuard';
import { AnalysisStepper } from '@/components/layout/AnalysisStepper';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/layout/LoadingState';
import { ErrorState } from '@/components/layout/ErrorState';
import { Button } from '@/components/layout/Button';
import { AnalysisStartAction } from '@/components/analyze/AnalysisStartAction';
import { AnalysisStatusPoller } from '@/components/analyze/AnalysisStatusPoller';
import { AnalysisFailurePanel } from '@/components/analyze/AnalysisFailurePanel';
import { CompletionCelebration } from '@/components/analyze/CompletionCelebration';
import { ChartReviewPanel } from '@/components/analyze/ChartReviewPanel';
import type { StatusResponse } from '@/types/analysis.types';
import { t } from '@/i18n';

export function AnalysisRunPage() {
  const { id, query: analysis } = useAnalysisGuard(4);
  const navigate = useNavigate();
  const run = useRunAnalysis(id);
  const rebuild = useGenerateReport(id);
  const [reviewing, setReviewing] = useState(false); // chart review opened from a completed analysis
  const [finished, setFinished] = useState<StatusResponse | null>(null);
  const a = analysis.data;

  const onStopped = useCallback(
    (status: StatusResponse) => {
      setFinished(status);
      analysis.refetch();
    },
    [analysis],
  );

  const start = () => {
    setFinished(null);
    setReviewing(false);
    run.mutate();
  };
  const writeReport = () => {
    setFinished(null);
    setReviewing(false);
    rebuild.mutate();
  };
  const rebuildReport = () => {
    setFinished(null);
    rebuild.mutate();
  };

  const openReport = useCallback(
    () => navigate(`/analyses/${id}/report`, { replace: true }),
    [navigate, id],
  );

  const status = a?.status;
  const processing =
    status === 'ANALYZING' ||
    status === 'REPORTING' ||
    (run.isSuccess && !finished) ||
    (rebuild.isSuccess && !finished);
  const failed = status === 'FAILED' && !processing;
  const justCompleted = finished?.status === 'COMPLETED';
  const justAnalyzed = finished?.status === 'ANALYZED';
  const alreadyCompleted = status === 'COMPLETED' && !finished && !processing;
  const analyzedOnly = status === 'ANALYZED' && !processing; // calculations done: chart review before the report

  return (
    <>
      <AnalysisStepper current={4} analysisId={id} status={status} />
      <PageHeader
        title={a?.name ?? t('Analyze')}
        subtitle={t(
          'One click runs the statistics, finds the insights, draws the charts and writes the verified report.',
        )}
      />
      {analysis.isLoading && <LoadingState />}
      {analysis.isError && <ErrorState error={analysis.error} onRetry={() => analysis.refetch()} />}
      {run.isError ? (
        <ErrorState
          error={run.error}
          onRetry={start}
          title={t('The analysis could not be started')}
        />
      ) : null}
      {rebuild.isError ? (
        <ErrorState
          error={rebuild.error}
          onRetry={rebuildReport}
          title={t('The report could not be started')}
        />
      ) : null}

      {a && status === 'DEFINED' && !processing && (
        <div className="rounded-xl border border-slate-200 bg-white p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div className="text-sm text-slate-600">
            <p className="font-medium text-slate-800">
              {t('Ready to analyze {file}', { file: a.dataset.file_name ?? t('your dataset') })}
            </p>
            <p>
              {t(
                'Statistics are calculated by software, never by AI. You then choose the charts, the AI explains the results, and every sentence is checked before you see it.',
              )}
            </p>
          </div>
          <AnalysisStartAction
            onStart={start}
            starting={run.isPending}
            label={t('Analyze and recommend charts')}
          />
        </div>
      )}

      {a && processing && !justCompleted && (
        <AnalysisStatusPoller
          analysisId={id}
          mode={status === 'REPORTING' || rebuild.isSuccess ? 'report' : 'run'}
          stopAt="ANALYZED"
          onStopped={onStopped}
        />
      )}

      {a && justCompleted && (
        <CompletionCelebration analysisName={a.name} onContinue={openReport} />
      )}

      {a && failed && (
        <AnalysisFailurePanel
          error={a.last_error}
          canRetry={a.dataset.transient_file_present && a.has_definitions}
          onRetry={start}
          retrying={run.isPending}
          analysisId={id}
          canRebuildReport={a.can_rebuild_report}
          onRebuildReport={rebuildReport}
          rebuilding={rebuild.isPending}
        />
      )}

      {a && analyzedOnly && !justAnalyzed && (
        <ChartReviewPanel
          analysisId={id}
          initialTheme={a.chart_theme}
          onConfirmed={writeReport}
          confirming={rebuild.isPending}
          confirmLabel={t('Write the executive summary')}
        />
      )}
      {a && justAnalyzed && (
        <ChartReviewPanel
          analysisId={id}
          initialTheme={a.chart_theme}
          onConfirmed={writeReport}
          confirming={rebuild.isPending}
          confirmLabel={t('Write the executive summary')}
        />
      )}
      {a && alreadyCompleted && reviewing && (
        <ChartReviewPanel
          analysisId={id}
          initialTheme={a.chart_theme}
          onConfirmed={writeReport}
          confirming={rebuild.isPending}
          confirmLabel={t('Rewrite the report with these charts')}
        />
      )}

      {a && alreadyCompleted && !reviewing && (
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div className="text-sm text-emerald-900">
            <p className="font-medium">{t('This analysis is complete')}</p>
            <p>
              {t(
                'The report is ready to read. Review the charts to change what it shows, or run again to recalculate from the current definitions.',
              )}
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setReviewing(true)}>
              {t('Review charts')}
            </Button>
            <Button
              variant="secondary"
              onClick={start}
              loading={run.isPending}
              disabled={!a.dataset.transient_file_present}
            >
              {t('Run again')}
            </Button>
            <Button onClick={openReport} icon={<ArrowRight size={16} aria-hidden="true" />}>
              {t('Open the report')}
            </Button>
          </div>
        </div>
      )}
    </>
  );
}
