// FE-SVC-003 analysis.service.ts: ANL-API-001..004, RUN-API-001/002
import { api } from './apiClient';
import { currentLocale } from '@/i18n';
import type { Analysis, AnalysisDetail, RunResponse, StatusResponse } from '@/types/analysis.types';
import type { Envelope, Paginated } from '@/types/api.types';

export const analysisService = {
  list: (page = 1, perPage = 20) =>
    api.get<Paginated<Analysis>>(`/analyses?page=${page}&per_page=${perPage}`),
  create: (name: string) => api.post<Envelope<Analysis>>('/analyses', { name }).then((r) => r.data),
  get: (id: string) => api.get<Envelope<AnalysisDetail>>(`/analyses/${id}`).then((r) => r.data),
  remove: (id: string) => api.delete<void>(`/analyses/${id}`),
  run: (id: string, idempotencyKey: string) =>
    api
      .post<Envelope<RunResponse>>(
        `/analyses/${id}/run`,
        { language: currentLocale() }, // FEAT-I18N-003: the report is produced in the interface language
        { 'Idempotency-Key': idempotencyKey },
      )
      .then((r) => r.data),
  status: (id: string, signal?: AbortSignal) =>
    api.get<Envelope<StatusResponse>>(`/analyses/${id}/status`, signal).then((r) => r.data),
};
