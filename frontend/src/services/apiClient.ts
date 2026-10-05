// FE-SVC-001 apiClient.ts: the single Laravel /api/v1 client. Base URL from env, Bearer header,
// JSON handling, multipart only for CSV upload, and error normalisation into ApiError.
// There is no FastAPI URL anywhere in the frontend (Specification Section 5).

import { env } from '@/config/env';
import { session } from '@/lib/session';
import { logger } from '@/lib/logger';
import { readLocale } from '@/lib/locale';
import type { ApiErrorBody, ApiErrorDetail } from '@/types/api.types';
import { t } from '@/i18n';

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly details: ApiErrorDetail[];

  constructor(status: number, body: ApiErrorBody | null, fallbackMessage: string) {
    super(body?.message ?? fallbackMessage);
    this.name = 'ApiError';
    this.status = status;
    this.code = body?.code ?? (status === 0 ? 'NETWORK_ERROR' : `HTTP_${status}`);
    this.details = body?.details ?? [];
  }

  /** Field-level issues for 422 responses, keyed by field name. */
  fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {};
    for (const d of this.details) if (!(d.field in out)) out[d.field] = d.issue;
    return out;
  }
}

type UnauthorizedHandler = () => void;
let onUnauthorized: UnauthorizedHandler | null = null;

/** The auth provider registers what to do on 401 (clear session, go to /login). */
export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
  onUnauthorized = handler;
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  body?: unknown;
  /** Multipart upload: only the CSV upload endpoint uses this. */
  formData?: FormData;
  headers?: Record<string, string>;
  signal?: AbortSignal;
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Dataspeak-Language': readLocale(), // FEAT-I18N-003: labels, chart titles and sentences come back in this language
    ...options.headers,
  };
  const token = session.token();
  if (token) headers.Authorization = `Bearer ${token}`;

  let body: BodyInit | undefined;
  if (options.formData) {
    body = options.formData; // browser sets the multipart boundary
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(options.body);
  }

  const abortError = () => new DOMException('The request was aborted.', 'AbortError');
  if (options.signal?.aborted) throw abortError();

  let response: Response;
  try {
    // The caller's signal is honoured by discarding the result rather than passed to fetch, so the
    // client does not depend on the AbortSignal implementation of the host (browser, jsdom, Node).
    response = await fetch(`${env.apiBaseUrl}${path}`, {
      method: options.method ?? 'GET',
      headers,
      body,
    });
  } catch {
    logger.warn('api.network_error', { path });
    throw new ApiError(0, null, 'The server could not be reached.');
  }
  if (options.signal?.aborted) throw abortError();

  if (response.status === 204) return undefined as T;

  const text = await response.text();
  let json: unknown = null;
  if (text) {
    try {
      json = JSON.parse(text);
    } catch {
      json = null;
    }
  }

  if (!response.ok) {
    const errorBody = (json as { error?: ApiErrorBody } | null)?.error ?? null;
    const apiError = new ApiError(
      response.status,
      errorBody,
      response.statusText || 'Request failed',
    );
    logger.warn('api.error', { path, status: response.status, code: apiError.code });
    if (response.status === 401 && onUnauthorized) onUnauthorized();
    throw apiError;
  }

  return json as T;
}

/** Binary download (PDF export). Same base URL, bearer header and error normalisation as request(). */
export async function download(path: string): Promise<{ blob: Blob; fileName: string | null }> {
  const headers: Record<string, string> = {
    Accept: 'application/pdf, application/json',
    'X-Dataspeak-Language': readLocale(),
  };
  const token = session.token();
  if (token) headers.Authorization = `Bearer ${token}`;
  let response: Response;
  try {
    response = await fetch(`${env.apiBaseUrl}${path}`, { headers });
  } catch {
    logger.warn('api.network_error', { path });
    throw new ApiError(0, null, 'The server could not be reached.');
  }
  if (!response.ok) {
    const errorBody = await response
      .json()
      .then((j: { error?: ApiErrorBody }) => j.error ?? null)
      .catch(() => null);
    const apiError = new ApiError(
      response.status,
      errorBody,
      response.statusText || 'Request failed',
    );
    logger.warn('api.error', { path, status: response.status, code: apiError.code });
    if (response.status === 401 && onUnauthorized) onUnauthorized();
    throw apiError;
  }
  const disposition = response.headers.get('Content-Disposition') ?? '';
  const match = /filename="?([^";]+)"?/.exec(disposition);
  return { blob: await response.blob(), fileName: match ? match[1] : null };
}

export const api = {
  get: <T>(path: string, signal?: AbortSignal) => request<T>(path, { signal }),
  post: <T>(path: string, body?: unknown, headers?: Record<string, string>) =>
    request<T>(path, { method: 'POST', body, headers }),
  put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
  upload: <T>(path: string, formData: FormData) => request<T>(path, { method: 'POST', formData }),
};

/** Business-friendly explanation for an error, by status (frontend prompt Section 4). */
export function describeError(error: unknown): string {
  if (!(error instanceof ApiError)) return t('Something went wrong. Please try again.');
  switch (error.status) {
    case 0:
      return t('The server could not be reached. Check your connection and try again.');
    case 401:
      return t('Your session has ended. Please log in again.');
    case 403:
      return t('You do not have access to this analysis.');
    case 404:
      return t('This analysis could not be found.');
    case 409:
      return error.message || t('This step is not available yet for this analysis.');
    case 422:
      return error.message || t('Please check the highlighted fields.');
    case 429:
      return t('Too many requests. Please wait a moment and try again.');
    case 502:
    case 503:
      return t('The analysis service is temporarily unavailable. Please retry in a moment.');
    default:
      return error.message || t('Something went wrong. Please try again.');
  }
}
