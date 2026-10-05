// FE-ANL-003 AnalysisStatusPoller: polls while active and notifies on terminal/stop states.
import { useEffect, useRef, useState } from 'react';
import { useAnalysisStatus, isStopped } from '@/hooks/useAnalysisStatus';
import type { AnalysisStatus, StatusResponse } from '@/types/analysis.types';
import { LoadingState } from '@/components/layout/LoadingState';
import { ErrorState } from '@/components/layout/ErrorState';
import { AnalysisProgress } from './AnalysisProgress';
import { t } from '@/i18n';

interface Props {
  analysisId: string;
  mode: 'profile' | 'run' | 'report' | 'full';
  stopAt?: AnalysisStatus;
  onStopped: (status: StatusResponse) => void;
}

export function AnalysisStatusPoller({ analysisId, mode, stopAt, onStopped }: Props) {
  const query = useAnalysisStatus(analysisId, { stopAt });
  const notified = useRef<string | null>(null);
  const [mountedAt] = useState(() => Date.now());

  useEffect(() => {
    const data = query.data;
    // Data cached by an earlier poller (previous job) must not end this one.
    if (!data || query.dataUpdatedAt < mountedAt) return;
    if (!isStopped(data.status, stopAt, data.job?.status)) return;
    const key = `${data.status}:${data.job?.job_id ?? ''}:${data.job?.status ?? ''}`;
    if (notified.current === key) return;
    notified.current = key;
    onStopped(data);
  }, [query.data, query.dataUpdatedAt, stopAt, onStopped, mountedAt]);

  if (query.isError)
    return (
      <ErrorState
        error={query.error}
        onRetry={() => query.refetch()}
        title={t('Could not read progress')}
      />
    );
  if (!query.data) return <LoadingState label={t('Checking progress…')} />;
  return <AnalysisProgress status={query.data} mode={mode} />;
}
