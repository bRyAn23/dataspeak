export interface User {
  id: string;
  name: string;
  email: string;
}

export type AnalysisStatus =
  | 'uploaded'
  | 'profiled'
  | 'defined'
  | 'analyzing'
  | 'analyzed'
  | 'generating'
  | 'completed'
  | 'failed';

export interface Analysis {
  id: string;
  title: string;
  fileName: string;
  rows: number;
  createdAt: string;
  status: AnalysisStatus;
  failedReason?: string;
}

export type ColumnType = 'Number' | 'Text' | 'Category' | 'Date';
export type ColumnRole = 'Measure' | 'Dimension' | 'Time' | 'Identifier' | 'Ignore';
export type ColumnUnit = '฿ THB' | '%' | 'Units' | 'None';
export type AggregationType = 'Sum' | 'Average' | 'Count' | 'Min' | 'Max';

export interface DatasetColumn {
  originalName: string;
  type: ColumnType;
  missing: number;
  missingPct: number;
  distinct: number;
  summary: string;
}

export interface FieldDefinition {
  originalName: string;
  businessName: string;
  description: string;
  role: ColumnRole;
  unit: ColumnUnit;
  aggregation?: AggregationType;
  suggested: boolean;
}

export interface Evidence {
  id: string;
  type: string;
  method: string;
  sourceColumns: { businessName: string; originalName: string }[];
  calculatedValues: { label: string; value: string }[];
}

export type ClaimStatus = 'supported' | 'rejected';

export interface Claim {
  id: string;
  sentence: string;
  status: ClaimStatus;
  evidenceId: string;
  claimedValue?: string;
  calculatedValue?: string;
  rejectionReason?: string;
}

export interface ChartData {
  type: 'bar' | 'line' | 'horizontalBar';
  data: Record<string, string | number>[];
  xKey: string;
  yKey: string;
  highlight?: string;
}

export interface Insight {
  rank: number;
  headline: string;
  claims: Claim[];
  chart: ChartData;
  evidenceId: string;
}

export interface Visualization {
  type: string;
  config: Record<string, unknown>;
}

export interface Report {
  title: string;
  datasetName: string;
  generatedAt: string;
  totalClaims: number;
  verifiedClaims: number;
  executiveClaims: Claim[];
  insights: Insight[];
  dataNotes: string[];
  allClaims: Claim[];
}

export interface DataProfile {
  rows: number;
  columns: number;
  duplicates: number;
  completeness: number;
  columnProfiles: DatasetColumn[];
}
