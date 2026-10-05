// Workflow steps (FE-CMP-003) and gating from the backend status. The stepper reflects real state and
// blocks steps whose prerequisites are not met (frontend prompt Section 8).

import type { AnalysisStatus } from '@/types/analysis.types';

export const STEPS = [
  { step: 1, key: 'upload', label: 'Upload' },
  { step: 2, key: 'profile', label: 'Profile' },
  { step: 3, key: 'define', label: 'Define' },
  { step: 4, key: 'analyze', label: 'Analyze' },
  { step: 5, key: 'report', label: 'Report' },
] as const;
export type StepKey = (typeof STEPS)[number]['key'];

/** Highest step the user may open for an analysis in this status. */
export function furthestStep(status: AnalysisStatus): number {
  switch (status) {
    case 'CREATED':
    case 'UPLOADED':
      return 1;
    case 'VALIDATED':
      return 2;
    case 'PROFILED':
      return 3;
    case 'DEFINED':
    case 'ANALYZING':
    case 'ANALYZED': // chart review happens on the Analyze step before the report exists
    case 'FAILED':
      return 4;
    case 'REPORTING': // the report page shows the progress of a (re)write
    case 'COMPLETED':
      return 5;
  }
}

export function stepPath(analysisId: string, step: number): string {
  switch (step) {
    case 1:
      return `/analyses/new?id=${analysisId}`;
    case 2:
      return `/analyses/${analysisId}/profile`;
    case 3:
      return `/analyses/${analysisId}/define`;
    case 4:
      return `/analyses/${analysisId}/analyze`;
    default:
      return `/analyses/${analysisId}/report`;
  }
}

/** Where "continue" should take the user for an analysis. */
export function continuePath(analysisId: string, status: AnalysisStatus): string {
  return stepPath(analysisId, furthestStep(status));
}
