// FE-PG-005 DataProfilePage: run profiling once (PROF-API-001) then show the stored profile (PROF-API-002).
import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, RotateCcw } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { profileService } from '@/services/profile.service';
import { keys, useProfile } from '@/hooks/useAnalyses';
import { useAnalysisGuard } from './useAnalysisGuard';
import { AnalysisStepper } from '@/components/layout/AnalysisStepper';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/layout/LoadingState';
import { ErrorState } from '@/components/layout/ErrorState';
import { DatasetOverviewCards } from '@/components/profile/DatasetOverviewCards';
import { ColumnProfileTable } from '@/components/profile/ColumnProfileTable';
import { QualityIssueList } from '@/components/profile/QualityIssueList';
import { deriveIssues } from '@/utils/quality';
import { ProfileContinueAction } from '@/components/profile/ProfileContinueAction';
import { AnalysisStatusPoller } from '@/components/analyze/AnalysisStatusPoller';
import { Button } from '@/components/layout/Button';
import type { LastError, StatusResponse } from '@/types/analysis.types';
import { t } from '@/i18n';

const FRIENDLY: Record<string, string> = {
  ANALYTICS_UNAVAILABLE:
    'The analysis service was unavailable. Your file is kept so you can try again.',
  ANALYTICS_INVALID_DATASET: 'The dataset could not be processed. Please upload it again.',
  UPLOAD_REQUIRED: 'The uploaded file is no longer available. Please upload it again.',
  JOB_INTERRUPTED:
    'Profiling stopped before it finished, for example because the background worker restarted. Try again.',
};

export function DataProfilePage() {
  const { id, query: analysis } = useAnalysisGuard(2);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const needsRun = analysis.data?.status === 'VALIDATED';
  const profile = useProfile(id, Boolean(analysis.data) && !needsRun);
  const [runError, setRunError] = useState<unknown>(null);
  const [jobError, setJobError] = useState<LastError | null>(null);
  const [polling, setPolling] = useState(false);
  const started = useRef(false);

  // PROF-API-001 answers 202; the job reports PROFILING then DEFINITION through RUN-API-002.
  useEffect(() => {
    if (!needsRun || started.current) return;
    started.current = true;
    setJobError(null);
    qc.removeQueries({ queryKey: keys.status(id) });
    profileService
      .run(id)
      .then(() => setPolling(true))
      .catch((e) => {
        setRunError(e);
        started.current = false;
      });
  }, [needsRun, id, qc]);

  const onStopped = useCallback(
    (s: StatusResponse) => {
      setPolling(false);
      if (s.status === 'VALIDATED' && s.job?.status === 'FAILED') {
        setJobError(
          s.job.error ?? {
            code: 'PROFILING_FAILED',
            message: t('Profiling could not be completed.'),
          },
        );
        started.current = false;
        return;
      }
      qc.invalidateQueries({ queryKey: keys.analysis(id) });
    },
    [qc, id],
  );

  const retry = () => {
    setJobError(null);
    setRunError(null);
    started.current = false;
    qc.invalidateQueries({ queryKey: keys.analysis(id) });
  };

  const data = profile.data;
  const issues = data ? deriveIssues(data.columns, data.dataset.rows) : [];

  return (
    <>
      <AnalysisStepper current={2} analysisId={id} status={analysis.data?.status} />
      <PageHeader
        title={analysis.data?.name ?? t('Data profile')}
        subtitle={t(
          'What Data Speak found in your file. Only column summaries are kept; the rows themselves are not stored.',
        )}
      />
      {(analysis.isLoading || (profile.isLoading && !data && !needsRun)) && (
        <LoadingState label={t('Loading profile…')} />
      )}
      {needsRun && !polling && !jobError && !runError && (
        <LoadingState label={t('Starting profiling…')} />
      )}
      {needsRun && polling && (
        <div className="space-y-3">
          <AnalysisStatusPoller
            analysisId={id}
            mode="profile"
            stopAt="PROFILED"
            onStopped={onStopped}
          />
          <p className="text-sm text-slate-500">
            {t(
              'Profiling reads the whole file and then asks the AI model for names and meanings. This usually takes between 10 and 60 seconds; you can leave this page and come back.',
            )}
          </p>
        </div>
      )}
      {jobError && (
        <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-6">
          <div className="flex items-start gap-3">
            <AlertTriangle size={20} className="mt-0.5 shrink-0 text-red-600" aria-hidden="true" />
            <div className="flex-1">
              <p className="font-medium text-red-800">{t('Profiling did not complete')}</p>
              <p className="mt-1 text-sm text-red-700">
                {FRIENDLY[jobError.code]
                  ? t(FRIENDLY[jobError.code])
                  : (jobError.message ?? t('Profiling could not be completed.'))}
              </p>
              <p className="mt-1 text-xs text-red-500">
                {t('Reference: {code}', { code: jobError.code })}
              </p>
              <div className="mt-4">
                <Button onClick={retry} icon={<RotateCcw size={16} aria-hidden="true" />}>
                  {t('Try again')}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}
      {analysis.isError && <ErrorState error={analysis.error} onRetry={() => analysis.refetch()} />}
      {runError ? (
        <ErrorState error={runError} onRetry={retry} title={t('Profiling could not be started')} />
      ) : null}
      {profile.isError && !needsRun && (
        <ErrorState error={profile.error} onRetry={() => profile.refetch()} />
      )}
      {data && (
        <div className="space-y-6">
          <DatasetOverviewCards profile={data} issueCount={issues.length} />
          <section aria-labelledby="columns-h">
            <h2 id="columns-h" className="mb-3 text-base font-semibold text-slate-900">
              {t('Columns')}
            </h2>
            {data.suggestions ? (
              <p role="status" className="mb-3 text-sm text-slate-600">
                {data.suggestions.ai_status === 'ready'
                  ? t(
                      'Names and meanings were suggested by the AI model{model} from the headers and detected types. You confirm or change them in the next step; they decide what is analysed and charted.',
                      {
                        model: data.suggestions.model ? ` (${data.suggestions.model})` : '',
                      },
                    )
                  : t(
                      'The AI model was not available, so names and meanings were suggested from the detected types only. You can adjust them in the next step.',
                    )}
              </p>
            ) : null}
            <ColumnProfileTable columns={data.columns} />
          </section>
          <section aria-labelledby="quality-h">
            <h2 id="quality-h" className="mb-3 text-base font-semibold text-slate-900">
              {t('Data quality')}
            </h2>
            <QualityIssueList issues={issues} />
          </section>
          <ProfileContinueAction
            onContinue={() => navigate(`/analyses/${id}/define`)}
            blocking={issues.some((i) => i.severity === 'insufficient')}
          />
        </div>
      )}
    </>
  );
}
