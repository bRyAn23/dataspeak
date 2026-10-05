// The one place backend enums become business-friendly labels (Frontend Program List, Section 12).
// Raw enum strings are never rendered.

import type { AnalysisStage, AnalysisStatus } from '@/types/analysis.types';
import type { ClaimStatus, InsightType, QualityStatus } from '@/types/report.types';
import type { InferredType, SemanticType } from '@/types/dataset.types';
import { t } from '@/i18n';

export const STATUS_LABELS: Record<AnalysisStatus, string> = {
  CREATED: 'Created',
  UPLOADED: 'Uploaded',
  VALIDATED: 'Validated',
  PROFILED: 'Profiled',
  DEFINED: 'Ready to analyze',
  ANALYZING: 'Analyzing',
  ANALYZED: 'Analysis complete',
  REPORTING: 'Preparing report',
  COMPLETED: 'Complete',
  FAILED: 'Needs attention',
};

export const STATUS_TONE: Record<
  AnalysisStatus,
  'neutral' | 'info' | 'success' | 'danger' | 'accent'
> = {
  CREATED: 'neutral',
  UPLOADED: 'info',
  VALIDATED: 'info',
  PROFILED: 'info',
  DEFINED: 'accent',
  ANALYZING: 'info',
  ANALYZED: 'accent',
  REPORTING: 'info',
  COMPLETED: 'success',
  FAILED: 'danger',
};

export const STAGE_LABELS: Record<AnalysisStage, string> = {
  UPLOAD: 'Uploading',
  VALIDATION: 'Checking the file',
  PROFILING: 'Profiling the data',
  DEFINITION: 'Defining fields',
  STATISTICAL_ANALYSIS: 'Calculating statistics',
  INSIGHT_DETECTION: 'Finding insights',
  VISUALIZATION: 'Choosing charts',
  AI_SYNTHESIS: 'Writing the narrative',
  CLAIM_VALIDATION: 'Verifying claims',
  REPORTING: 'Assembling the report',
  COMPLETE: 'Done',
};

export const TERMINAL_STATUSES: readonly AnalysisStatus[] = ['COMPLETED', 'FAILED'];

export const INFERRED_TYPE_LABELS: Record<InferredType, string> = {
  NUMERIC: 'Number',
  INTEGER: 'Whole number',
  DATE: 'Date',
  DATETIME: 'Date and time',
  BOOLEAN: 'Yes / no',
  CATEGORY: 'Category',
  TEXT: 'Text',
  IDENTIFIER: 'Identifier',
  UNKNOWN: 'Unknown',
};

export const SEMANTIC_TYPE_LABELS: Record<SemanticType, string> = {
  NUMBER: 'Number (measure)',
  CURRENCY: 'Currency (measure)',
  PERCENTAGE: 'Percentage (measure)',
  DATE: 'Date (time axis)',
  CATEGORY: 'Category (group)',
  BOOLEAN: 'Yes / no (group)',
  TEXT: 'Text (not analysed)',
  IDENTIFIER: 'Identifier (not analysed)',
  IGNORE: 'Ignore this column',
};

export const AGGREGATION_LABELS: Record<string, string> = {
  SUM: 'Sum',
  AVERAGE: 'Average',
  COUNT: 'Count',
  MIN: 'Min',
  MAX: 'Max',
  NONE: 'None',
};

export const INSIGHT_TYPE_LABELS: Record<InsightType, string> = {
  TREND_CHANGE: 'Trend',
  GROUP_DIFFERENCE: 'Group difference',
  CONCENTRATION: 'Concentration',
  ASSOCIATION: 'Association',
  DISTRIBUTION: 'Distribution',
  DATA_QUALITY: 'Data quality',
};

export const QUALITY_LABELS: Record<QualityStatus, string> = {
  ACCEPTABLE: 'Reliable',
  WARNING: 'Use with care',
  INSUFFICIENT: 'Not enough data',
};

export const CLAIM_STATUS_LABELS: Record<ClaimStatus, string> = {
  PENDING: 'Pending check',
  SUPPORTED: 'Verified',
  UNSUPPORTED: 'Unsupported',
  REJECTED: 'Rejected',
  NEEDS_REVIEW: 'Needs review',
};

export const VALIDATION_REASON_LABELS: Record<string, string> = {
  UNKNOWN_EVIDENCE: 'Refers to evidence that does not exist',
  NO_EVIDENCE_REFERENCE: 'No supporting evidence',
  DIGITS_OUTSIDE_PLACEHOLDER: 'Contains a number not taken from evidence',
  PLACEHOLDER_FIELD_NOT_ALLOWED: 'Uses an unsupported evidence field',
  PLACEHOLDER_UNKNOWN_EVIDENCE: 'Placeholder points to unknown evidence',
  PLACEHOLDER_UNRESOLVABLE: 'Placeholder could not be filled from evidence',
  PLACEHOLDER_EVIDENCE_NOT_CITED: 'Uses evidence it does not cite',
  CAUSAL_LANGUAGE: 'States a cause the data cannot show',
  LOW_QUALITY_EVIDENCE_AS_FINDING: 'Presents uncertain evidence as a finding',
  INSUFFICIENT_EVIDENCE_AS_FINDING: 'Based on insufficient data',
  DIRECTION_CONTRADICTION: 'Direction contradicts the evidence',
  REPEATED_PLACEHOLDER: 'Uses the same value twice, which usually means a name was misplaced',
  GROUP_NOT_NAMED: 'States a share without naming the group it belongs to',
  PLACED_BY_SOFTWARE: 'Placed in the report section that matches its evidence',
  NOT_IN_NARRATIVE: 'Shown as a key figure or data note rather than in the narrative',
  REPAIRED: 'Rewritten once by the model to use evidence placeholders, then re-verified',
  RECLASSIFIED_AS_CAVEAT: 'Moved to data notes: it rests only on data-quality evidence',
  CITATION_COMPLETED: 'Evidence referenced in the text was added to its citations',
};

export function describeReason(reason: string): string {
  const [code, detail] = reason.split(':');
  const label = VALIDATION_REASON_LABELS[code]
    ? t(VALIDATION_REASON_LABELS[code])
    : code.replace(/_/g, ' ').toLowerCase();
  return detail ? `${label} (${detail.replace(/_/g, ' ')})` : label;
}

export function statusLabel(status: AnalysisStatus): string {
  return t(STATUS_LABELS[status] ?? 'Unknown');
}

export function stageLabel(stage: AnalysisStage | string | null | undefined): string {
  return t((stage && STAGE_LABELS[stage as AnalysisStage]) || 'Working');
}
