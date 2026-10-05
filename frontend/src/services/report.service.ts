// FE-SVC-007 report.service.ts: INS-API-001, VIS-API-001, RPT-API-001/002/003, TRC-API-001
import { api, download } from './apiClient';
import { currentLocale } from '@/i18n';
import type { RunResponse } from '@/types/analysis.types';
import type { Envelope } from '@/types/api.types';
import type { ChartOption, Claim, Insight, Report, Visualization } from '@/types/report.types';

/** RPT-API-004 answer: a finished (or partly finished) translation, or a background one already running. */
export interface TranslateResult {
  language: string;
  status?: 'in_progress';
  translated?: number;
  pending?: number;
  total?: number;
  ai_status?: string;
}

export const reportService = {
  insights: (id: string) =>
    api.get<Envelope<Insight[]>>(`/analyses/${id}/insights`).then((r) => r.data),
  visualizations: (id: string) =>
    api.get<Envelope<Visualization[]>>(`/analyses/${id}/visualizations`).then((r) => r.data),
  /** VIS-API-002: charts that can be added. */
  chartOptions: (id: string) =>
    api.get<Envelope<ChartOption[]>>(`/analyses/${id}/visualizations/options`).then((r) => r.data),
  /** VIS-API-003: keep/remove recommended charts and add chosen ones. */
  selectCharts: (id: string, selected: string[], add: string[], theme?: string) =>
    api
      .put<Envelope<Visualization[]>>(`/analyses/${id}/visualizations/selection`, {
        selected,
        add,
        ...(theme ? { theme } : {}),
      })
      .then((r) => r.data),
  /** RPT-API-001 is asynchronous: 202 then poll status until COMPLETED. */
  generate: (id: string, idempotencyKey: string) =>
    api
      .post<Envelope<RunResponse>>(
        `/analyses/${id}/report`,
        { language: currentLocale() }, // FEAT-I18N-003
        { 'Idempotency-Key': idempotencyKey },
      )
      .then((r) => r.data),
  get: (id: string) => api.get<Envelope<Report>>(`/analyses/${id}/report`).then((r) => r.data),
  /** RPT-API-004: translate the existing verified report into a language (no re-interpretation). */
  translate: (id: string, language: string) =>
    api
      .post<Envelope<TranslateResult>>(`/analyses/${id}/report/translate`, { language })
      .then((r) => r.data),
  /** RPT-API-003: server-rendered PDF with cover, letterhead and page numbers. */
  pdf: (id: string) =>
    download(`/analyses/${id}/report/pdf`).then(({ blob, fileName }) => ({
      blob,
      fileName: fileName ?? `DataSpeak-report-${id}.pdf`,
    })),
  claim: (id: string, claimId: string) =>
    api.get<Envelope<Claim>>(`/analyses/${id}/claims/${claimId}`).then((r) => r.data),
};
