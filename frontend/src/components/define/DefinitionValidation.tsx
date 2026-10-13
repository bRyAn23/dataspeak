// FE-DEF-003 DefinitionValidation: ensures required semantic metadata is valid before saving.
import type {
  ColumnProfile,
  FieldDefinition,
  InferredType,
  SemanticType,
} from '@/types/dataset.types';
import { hasNonLatinScript, humanize } from '@/utils/naming';
import { t } from '@/i18n';

const MEASURES: SemanticType[] = ['NUMBER', 'CURRENCY', 'PERCENTAGE'];

export function suggestedType(inferred: InferredType): SemanticType {
  switch (inferred) {
    case 'NUMERIC':
    case 'INTEGER':
      return 'NUMBER';
    case 'DATE':
    case 'DATETIME':
      return 'DATE';
    case 'CATEGORY':
      return 'CATEGORY';
    case 'BOOLEAN':
      return 'BOOLEAN';
    case 'IDENTIFIER':
      return 'IDENTIFIER';
    default:
      return 'TEXT';
  }
}

export function initialDefinitions(columns: ColumnProfile[]): FieldDefinition[] {
  return columns.map((c) => ({
    column: c.name,
    business_name: c.definition?.business_name ?? humanize(c.name),
    description: c.definition?.description ?? '',
    semantic_type: c.definition?.semantic_type ?? suggestedType(c.inferred_type),
    unit: c.definition?.unit ?? '',
    aggregation:
      c.definition?.aggregation ??
      (MEASURES.includes(suggestedType(c.inferred_type)) ? 'SUM' : null),
    is_suggested: c.definition?.is_suggested ?? true,
    suggestion_reason: c.definition?.suggestion_reason ?? null,
  }));
}

/** Client-side checks mirroring DEF-API-001 rules. Keyed like the server: definitions.<index>.<field>. */
export function validateDefinitions(defs: FieldDefinition[]): Record<string, string> {
  const errors: Record<string, string> = {};
  defs.forEach((d, i) => {
    if (!d.business_name.trim())
      errors[`definitions.${i}.business_name`] = t('Give this column a business name.');
    else if (hasNonLatinScript(d.business_name))
      errors[`definitions.${i}.business_name`] = t(
        'Use an English business name; it is translated for reports in other languages. "Suggest again" fills one in.',
      );
    if (d.semantic_type === 'CURRENCY' && !(d.unit ?? '').trim())
      errors[`definitions.${i}.unit`] = t('Currency needs a unit, for example THB.');
    if ((d.unit ?? '').length > 32)
      errors[`definitions.${i}.unit`] = t('Unit must be 32 characters or fewer.');
  });
  const measures = defs.filter((d) => MEASURES.includes(d.semantic_type));
  if (measures.length === 0)
    errors.form = t(
      'Mark at least one column as a number, currency or percentage so there is something to analyse.',
    );
  return errors;
}

export function isMeasure(type: SemanticType): boolean {
  return MEASURES.includes(type);
}
