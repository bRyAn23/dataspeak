// FE-SVC-006 definition.service.ts: DEF-API-001, DEF-API-002
import { api } from './apiClient';
import type { DefinitionSuggestion, FieldDefinition, SuggestionMeta } from '@/types/dataset.types';

export const definitionService = {
  save: (id: string, definitions: FieldDefinition[]) =>
    api.put<{ data: FieldDefinition[]; meta: { status: string } }>(`/analyses/${id}/definitions`, {
      definitions,
    }),
  /** DEF-API-002: re-run the definition suggestions (English name, meaning, unit, aggregation). */
  suggestNames: (id: string) =>
    api.post<{ data: DefinitionSuggestion[]; meta: SuggestionMeta }>(
      `/analyses/${id}/definitions/suggest-names`,
    ),
};
