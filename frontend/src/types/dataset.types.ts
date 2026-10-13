// FE-TYPE-002 dataset.types.ts: column, profile, quality and definition types.

export type InferredType =
  | 'NUMERIC'
  | 'INTEGER'
  | 'DATE'
  | 'DATETIME'
  | 'BOOLEAN'
  | 'CATEGORY'
  | 'TEXT'
  | 'IDENTIFIER'
  | 'UNKNOWN';

export const SEMANTIC_TYPES = [
  'NUMBER',
  'CURRENCY',
  'PERCENTAGE',
  'DATE',
  'CATEGORY',
  'TEXT',
  'IDENTIFIER',
  'BOOLEAN',
  'IGNORE',
] as const;
export type SemanticType = (typeof SEMANTIC_TYPES)[number];

export const AGGREGATIONS = ['SUM', 'AVERAGE', 'COUNT', 'MIN', 'MAX', 'NONE'] as const;
export type Aggregation = (typeof AGGREGATIONS)[number];

export interface Finding {
  code: string;
  message: string;
  column?: string | null;
}

export interface ValidateResponse {
  analysis_id: string;
  status: string;
  valid: boolean;
  row_count: number | null;
  column_count: number | null;
  warnings: Finding[];
  errors: Finding[];
}

export interface FieldDefinition {
  column: string;
  business_name: string;
  description?: string | null;
  semantic_type: SemanticType;
  unit?: string | null;
  aggregation?: Aggregation | null;
  is_suggested?: boolean;
  /** Why this meaning and aggregation were suggested (FEAT-DEF-004); present while the row is still a suggestion. */
  suggestion_reason?: string | null;
  /** Client-only: where the current business name came from (AI translation or software). */
  name_source?: 'ai' | 'software';
}

/** DEF-API-002 item: a complete suggested definition (English name, meaning, unit, aggregation). */
export interface DefinitionSuggestion {
  column: string;
  business_name: string;
  semantic_type: SemanticType;
  unit: string | null;
  aggregation: Aggregation | null;
  description: string | null;
  /** Why this meaning and aggregation were chosen, in plain words. */
  reason?: string | null;
  source: 'ai' | 'software';
}

export interface SuggestionMeta {
  ai_status: 'ready' | 'unavailable' | 'disabled' | 'invalid_output';
  model: string | null;
  prompt_version?: string;
  ai_count: number;
}

export interface ColumnProfile {
  name: string;
  position: number;
  inferred_type: InferredType;
  null_count: number;
  null_rate: number;
  distinct_count: number | null;
  stats: Record<string, number | string | null>;
  definition: Omit<FieldDefinition, 'column'> | null;
}

export interface ProfileResponse {
  analysis_id: string;
  status: string;
  dataset: {
    rows: number | null;
    columns: number | null;
    completeness_pct: number | null;
  };
  columns: ColumnProfile[];
  /** How the is_suggested definitions were produced at profiling time (FEAT-DEF-003). */
  suggestions?: SuggestionMeta | null;
}

export interface DefinitionsInput {
  definitions: FieldDefinition[];
}
