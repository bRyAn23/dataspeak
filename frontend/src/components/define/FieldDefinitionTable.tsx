// FE-DEF-001 FieldDefinitionTable: edit business name, semantic type, unit and aggregation per column.
import type { ColumnProfile, FieldDefinition } from '@/types/dataset.types';
import { AGGREGATIONS } from '@/types/dataset.types';
import { AGGREGATION_LABELS } from '@/utils/statusLabels';
import { INFERRED_TYPE_LABELS } from '@/utils/statusLabels';
import { SemanticTypeSelect } from './SemanticTypeSelect';
import { isMeasure } from './DefinitionValidation';
import { t } from '@/i18n';

interface Props {
  columns: ColumnProfile[];
  definitions: FieldDefinition[];
  errors: Record<string, string>;
  onChange: (index: number, patch: Partial<FieldDefinition>) => void;
  disabled?: boolean;
}

const input =
  'w-full px-2.5 py-2 text-sm rounded-lg border bg-white focus:outline-none focus:ring-2 focus:ring-brand-700';

export function FieldDefinitionTable({ columns, definitions, errors, onChange, disabled }: Props) {
  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table className="min-w-full text-sm">
        <caption className="sr-only">Field definitions</caption>
        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th scope="col" className="px-4 py-3">
              {t('Column')}
            </th>
            <th scope="col" className="px-4 py-3">
              {t('Business name')}
            </th>
            <th scope="col" className="px-4 py-3">
              {t('Meaning')}
            </th>
            <th scope="col" className="px-4 py-3">
              {t('Unit')}
            </th>
            <th scope="col" className="px-4 py-3">
              {t('Aggregate by')}
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {definitions.map((d, i) => {
            const col = columns.find((c) => c.name === d.column);
            const nameErr = errors[`definitions.${i}.business_name`];
            const unitErr = errors[`definitions.${i}.unit`];
            const measure = isMeasure(d.semantic_type);
            return (
              <tr key={d.column} className="align-top">
                <td className="px-4 py-3">
                  <div className="font-medium text-slate-800 break-words max-w-[12rem]">
                    {d.column}
                  </div>
                  {col && (
                    <div className="text-xs text-slate-500">
                      {t(INFERRED_TYPE_LABELS[col.inferred_type])}
                    </div>
                  )}
                </td>
                <td className="px-4 py-3 min-w-[12rem]">
                  <label htmlFor={`bn-${i}`} className="sr-only">
                    {t('Business name for {column}', { column: d.column })}
                  </label>
                  <input
                    id={`bn-${i}`}
                    value={d.business_name}
                    maxLength={255}
                    disabled={disabled}
                    onChange={(e) =>
                      onChange(i, { business_name: e.target.value, is_suggested: false })
                    }
                    aria-invalid={Boolean(nameErr)}
                    aria-describedby={nameErr ? `bn-${i}-err` : undefined}
                    className={`${input} ${nameErr ? 'border-red-400' : 'border-slate-300'}`}
                  />
                  {nameErr && (
                    <p id={`bn-${i}-err`} className="mt-1 text-xs text-red-600">
                      {nameErr}
                    </p>
                  )}
                  {!nameErr && d.is_suggested && (
                    <p className="mt-1 text-xs text-slate-500">
                      {d.name_source === 'ai'
                        ? t('AI suggestion, check it')
                        : t('Suggested, check it')}
                      {d.suggestion_reason ? (
                        <span className="block text-slate-600">{d.suggestion_reason}</span>
                      ) : null}
                    </p>
                  )}
                </td>
                <td className="px-4 py-3 min-w-[13rem]">
                  <label htmlFor={`st-${i}`} className="sr-only">
                    {t('Meaning of {column}', { column: d.column })}
                  </label>
                  <SemanticTypeSelect
                    id={`st-${i}`}
                    value={d.semantic_type}
                    disabled={disabled}
                    onChange={(semantic_type) =>
                      onChange(i, {
                        semantic_type,
                        is_suggested: false,
                        aggregation: isMeasure(semantic_type) ? (d.aggregation ?? 'SUM') : null,
                      })
                    }
                  />
                </td>
                <td className="px-4 py-3 min-w-[7rem]">
                  <label htmlFor={`unit-${i}`} className="sr-only">
                    {t('Unit for {column}', { column: d.column })}
                  </label>
                  <input
                    id={`unit-${i}`}
                    value={d.unit ?? ''}
                    maxLength={32}
                    placeholder={
                      d.semantic_type === 'CURRENCY'
                        ? 'THB'
                        : d.semantic_type === 'PERCENTAGE'
                          ? '%'
                          : ''
                    }
                    disabled={disabled || !measure}
                    onChange={(e) => onChange(i, { unit: e.target.value, is_suggested: false })}
                    aria-invalid={Boolean(unitErr)}
                    aria-describedby={unitErr ? `unit-${i}-err` : undefined}
                    className={`${input} ${unitErr ? 'border-red-400' : 'border-slate-300'} disabled:bg-slate-50`}
                  />
                  {unitErr && (
                    <p id={`unit-${i}-err`} className="mt-1 text-xs text-red-600">
                      {unitErr}
                    </p>
                  )}
                </td>
                <td className="px-4 py-3 min-w-[8rem]">
                  <label htmlFor={`agg-${i}`} className="sr-only">
                    {t('Aggregation for {column}', { column: d.column })}
                  </label>
                  <select
                    id={`agg-${i}`}
                    value={d.aggregation ?? 'NONE'}
                    disabled={disabled || !measure}
                    onChange={(e) =>
                      onChange(i, {
                        aggregation: e.target.value as FieldDefinition['aggregation'],
                        is_suggested: false,
                      })
                    }
                    className={`${input} border-slate-300 disabled:bg-slate-50`}
                  >
                    {AGGREGATIONS.map((a) => (
                      <option key={a} value={a}>
                        {t(AGGREGATION_LABELS[a])}
                      </option>
                    ))}
                  </select>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
