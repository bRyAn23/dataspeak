// FE-TYPE-003 report.types.ts: evidence, insight, claim, chart and report types.

export type QualityStatus = 'ACCEPTABLE' | 'WARNING' | 'INSUFFICIENT';
export type AnalysisFamily =
  'DESCRIPTIVE' | 'TREND' | 'GROUP_COMPARISON' | 'ASSOCIATION' | 'STATISTICAL_TEST' | 'QUALITY';
export type InsightType =
  | 'TREND_CHANGE'
  | 'GROUP_DIFFERENCE'
  | 'CONCENTRATION'
  | 'ASSOCIATION'
  | 'DISTRIBUTION'
  | 'DATA_QUALITY';
export type ChartType = 'LINE' | 'BAR' | 'SCATTER' | 'HISTOGRAM' | 'TABLE' | 'PIE';
export type ClaimKind = 'EXECUTIVE_SUMMARY' | 'FINDING' | 'METRIC' | 'CAVEAT';
export type ClaimStatus = 'PENDING' | 'SUPPORTED' | 'UNSUPPORTED' | 'REJECTED' | 'NEEDS_REVIEW';
export type AiStatus = 'ready' | 'no_valid_claims' | 'unavailable' | 'disabled';

export interface Evidence {
  evidence_id: string;
  analysis_family: AnalysisFamily;
  metric: string;
  method: string;
  value: number | null;
  display_value: string | null;
  source_columns: string[];
  metric_column?: string | null;
  dimension_column?: string | null;
  parameters: Record<string, unknown>;
  quality: { status: QualityStatus };
  payload: Record<string, unknown> | null;
  created_at: string | null;
  /** Business names from the user's definitions (present when the API resolved them). */
  label?: string | null;
  dimension?: string | null;
  group?: string | null;
  period_from?: string | null;
  period_to?: string | null;
}

export interface Insight {
  rank: number;
  insight_type: InsightType;
  headline: string;
  description: string | null;
  score: number;
  is_selected: boolean;
  evidence_id: string | null;
  evidence_ids: string[];
  evidence?: Evidence;
}

export interface Axis {
  field: string;
  label: string;
  unit?: string | null;
}

export interface ChartSummaryItem {
  text: string;
  claim_id: string | null;
  evidence_key?: string;
  source: 'narrative' | 'calculated';
}

export interface Visualization {
  id: string;
  type: ChartType;
  title: string;
  x: Axis | null;
  y: Axis | null;
  unit_missing: boolean;
  series: Record<string, string | number | null>[];
  evidence_ids: string[];
  insight_rank: number | null;
  position: number;
  /** Recommended by the analytics service or added by the user at chart review (FEAT-VIS-003). */
  source: 'RECOMMENDED' | 'USER';
  /** Kept for the report. */
  is_selected: boolean;
  /** Plain-language reading of this chart: checked narrative statements, or calculated sentences. */
  summary?: ChartSummaryItem[];
}

export interface ClaimGeneration {
  id: string;
  model: string | null;
  prompt_version: string | null;
  status: string;
}

export interface Claim {
  id: string;
  kind: ClaimKind;
  statement: string;
  statement_template: string;
  claimed_value: string | null;
  computed_value: string | null;
  status: ClaimStatus;
  validation: { status: ClaimStatus; method: string | null; reasons: string[] };
  in_report: boolean;
  position: number | null;
  evidence?: Evidence[];
  primary_evidence_id?: string | null;
  generation: ClaimGeneration | null;
}

export interface SummaryMetric {
  evidence_key: string;
  label: string;
  display_value: string;
  unit: string | null;
}

export interface ReportSection {
  key: 'executive_summary' | 'findings' | 'limitations' | 'visualizations' | string;
  title: string;
  claim_ids?: string[];
  insight_ranks?: number[];
  visualization_ids?: number[];
  ai_status?: AiStatus;
  note?: string | null;
}

export interface DataNote {
  severity: QualityStatus;
  metric: string;
  columns: string[];
  labels?: string[];
  display_value: string | null;
}

export interface Report {
  id: string;
  analysis_id: string;
  status: 'DRAFT' | 'GENERATING' | 'READY' | 'FAILED';
  /** Language the narrative, labels and PDF were produced in (FEAT-I18N-003). */
  language?: 'en' | 'th' | 'zh' | 'my';
  /** Colour theme chosen at chart review, applied to every chart and the PDF (FEAT-VIS-004). */
  chart_theme?: string;
  /** Language the software-written parts were rendered in for this response (the interface language). */
  display_language?: 'en' | 'th' | 'zh' | 'my';
  /** Whether every narrative statement has a verified translation for display_language (RPT-API-004). */
  translation?: { complete: boolean; translated: number; total: number; in_progress?: boolean };
  title: string;
  dataset_name: string | null;
  generated_at: string | null;
  claims_total: number;
  claims_supported: number;
  ai: {
    status: AiStatus;
    note: string | null;
    model: string | null;
    prompt_version: string | null;
    generation_id: string | null;
  };
  summary_metrics: SummaryMetric[];
  sections: ReportSection[];
  data_notes: DataNote[];
  insights: Insight[];
  visualizations: Visualization[];
  claims: Claim[];
}

/** VIS-API-002: a chart the calculated evidence can support that is not in the analysis yet. */
export interface ChartOption {
  key: string;
  type: ChartType;
  title: string;
  x_label: string | null;
  y_label: string | null;
  unit: string | null;
  points: number;
  evidence_ids: string[];
  insight_rank: number | null;
  /** Full specification so the chart can be drawn with its data before it is saved. */
  x: Axis | null;
  y: Axis | null;
  unit_missing: boolean;
  series: Record<string, string | number | null>[];
}
