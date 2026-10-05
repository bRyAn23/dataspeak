// FE-SVC-004 dataset.service.ts: DATA-API-001 (multipart upload) and DATA-API-002 (validate)
import { api } from './apiClient';
import type { Analysis } from '@/types/analysis.types';
import type { Envelope } from '@/types/api.types';
import type { ValidateResponse } from '@/types/dataset.types';

/** DATA-API-001 meta: how the upload was taken in (an .xlsx is converted to CSV from one worksheet). */
export type UploadSource =
  | { format: 'csv' }
  | { format: 'xlsx'; sheet: string; sheets_total: number; rows: number; columns: number };

export const datasetService = {
  /** The File object is handed straight to FormData; its contents are never read in the browser. */
  upload: (id: string, file: File) => {
    const form = new FormData();
    form.append('file', file, file.name);
    return api
      .upload<Envelope<Analysis> & { meta?: { source?: UploadSource } }>(
        `/analyses/${id}/dataset`,
        form,
      )
      .then((r) => ({ analysis: r.data, source: r.meta?.source ?? { format: 'csv' as const } }));
  },
  validate: (id: string) =>
    api.post<Envelope<ValidateResponse>>(`/analyses/${id}/validate`).then((r) => r.data),
};
