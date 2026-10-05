// FE-SVC-005 profile.service.ts: PROF-API-001 (run) and PROF-API-002 (read)
import { api } from './apiClient';
import type { Envelope } from '@/types/api.types';
import type { RunResponse } from '@/types/analysis.types';
import type { ProfileResponse } from '@/types/dataset.types';

export const profileService = {
  /** PROF-API-001 is asynchronous: 202 with a job id, then poll RUN-API-002 until PROFILED. */
  run: (id: string) =>
    api.post<Envelope<RunResponse>>(`/analyses/${id}/profile`).then((r) => r.data),
  get: (id: string) =>
    api.get<Envelope<ProfileResponse>>(`/analyses/${id}/profile`).then((r) => r.data),
};
