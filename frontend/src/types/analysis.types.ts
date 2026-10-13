// FE-TYPE-001 analysis.types.ts: analysis, stage and status types mirroring the Laravel contract.
// Enum values come from the data dictionary (docs/Data_Speak_Data_Dictionary.xlsx, Enumerations).

export const ANALYSIS_STATUSES = [
  'CREATED',
  'UPLOADED',
  'VALIDATED',
  'PROFILED',
  'DEFINED',
  'ANALYZING',
  'ANALYZED',
  'REPORTING',
  'COMPLETED',
  'FAILED',
] as const;
export type AnalysisStatus = (typeof ANALYSIS_STATUSES)[number];

export const ANALYSIS_STAGES = [
  'UPLOAD',
  'VALIDATION',
  'PROFILING',
  'DEFINITION',
  'STATISTICAL_ANALYSIS',
  'INSIGHT_DETECTION',
  'VISUALIZATION',
  'AI_SYNTHESIS',
  'CLAIM_VALIDATION',
  'REPORTING',
  'COMPLETE',
] as const;
export type AnalysisStage = (typeof ANALYSIS_STAGES)[number];

export type StageStatus = 'PENDING' | 'RUNNING' | 'SUCCEEDED' | 'FAILED' | 'SKIPPED';
export type JobStatus = 'QUEUED' | 'RUNNING' | 'SUCCEEDED' | 'FAILED';

export interface User {
  id: string;
  name: string;
  email: string;
  role: 'USER' | 'ADMIN' | null;
  email_verified: boolean;
  email_verified_at: string | null;
  /** False when the server runs without email verification (REQUIRE_VERIFIED_EMAIL=false). */
  verification_required?: boolean;
  created_at: string | null;
}

export interface AuthResponse {
  token: string;
  user: User;
  /** Present on register (AUTH-API-001): whether a confirmation email is required and was sent. */
  verification?: { required: boolean; email_sent: boolean };
}

export interface AnalysisDataset {
  file_name: string | null;
  size_bytes: number | null;
  sha256: string | null;
  row_count: number | null;
  column_count: number | null;
  completeness_pct: number | null;
  uploaded_at: string | null;
  purged_at: string | null;
  transient_file_present: boolean;
}

export interface LastError {
  code: string;
  message: string | null;
}

export interface Analysis {
  id: string;
  name: string;
  /** Colour theme for every chart in the report and the PDF, chosen at chart review (FEAT-VIS-004). */
  chart_theme?: string;
  status: AnalysisStatus;
  current_stage: AnalysisStage;
  dataset: AnalysisDataset;
  last_error: LastError | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface StageRecord {
  stage: AnalysisStage;
  status: StageStatus;
  attempt_no: number;
  progress: number;
  started_at: string | null;
  finished_at: string | null;
  duration_ms: number | null;
  error_code: string | null;
  result_summary: Record<string, unknown> | null;
}

export interface JobProgress {
  job_id: string | null;
  /** 'run' (analysis + report) or 'profile' (profiling + suggested definitions). */
  kind?: 'run' | 'profile';
  status: JobStatus;
  stage: string | null;
  progress: number;
  /** Set when status is FAILED and the analysis itself did not change state (profiling). */
  error?: LastError | null;
  updated_at: string;
}

export interface AnalysisDetail extends Analysis {
  job: JobProgress | null;
  stages: StageRecord[];
  has_definitions: boolean;
  has_report: boolean;
  /** FAILED during the report phase with the calculated results still on file: the report can be rebuilt. */
  can_rebuild_report: boolean;
}

export interface RunResponse {
  analysis_id: string;
  job_id: string;
  status: JobStatus;
}

export interface StatusResponse {
  analysis_id: string;
  status: AnalysisStatus;
  stage: AnalysisStage;
  progress: number;
  job: JobProgress | null;
  is_terminal: boolean;
  last_error: LastError | null;
  poll_seconds: number;
}
