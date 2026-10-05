// FE-HOOK-001 useAnalysisStatus: polls RUN-API-002 every 2 seconds while the analysis is processing.
// Stops on terminal states (COMPLETED, FAILED), on the configured stop state (e.g. ANALYZED for the
// analyze step), on unmount, and while the tab is hidden (TanStack pauses background intervals).

import { useQuery } from '@tanstack/react-query';
import { analysisService } from '@/services/analysis.service';
import type { AnalysisStatus, JobStatus, StatusResponse } from '@/types/analysis.types';
import { TERMINAL_STATUSES } from '@/utils/statusLabels';

export const POLL_MS = 2000;

export interface UseAnalysisStatusOptions {
  /** Additional status at which polling stops (the step's own end state). */
  stopAt?: AnalysisStatus;
  enabled?: boolean;
}

export function isStopped(
  status: AnalysisStatus | undefined,
  stopAt?: AnalysisStatus,
  jobStatus?: JobStatus | null,
): boolean {
  if (!status) return false;
  return TERMINAL_STATUSES.includes(status) || status === stopAt || jobStatus === 'FAILED';
}

export function useAnalysisStatus(
  analysisId: string | undefined,
  options: UseAnalysisStatusOptions = {},
) {
  return useQuery<StatusResponse>({
    queryKey: ['analysis', analysisId, 'status'],
    refetchOnMount: 'always',
    queryFn: ({ signal }) => analysisService.status(analysisId as string, signal),
    enabled: Boolean(analysisId) && (options.enabled ?? true),
    staleTime: 0,
    refetchInterval: (query) => {
      const data = query.state.data;
      if (!data) return POLL_MS;
      return isStopped(data.status, options.stopAt, data.job?.status)
        ? false
        : (data.poll_seconds || 2) * 1000;
    },
    refetchIntervalInBackground: false,
  });
}
