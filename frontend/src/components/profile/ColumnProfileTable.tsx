// FE-PRO-002 ColumnProfileTable: column name, type, completeness and a derived summary (no raw values).
import type { ColumnProfile } from '@/types/dataset.types';
import {
  AGGREGATION_LABELS,
  INFERRED_TYPE_LABELS,
  SEMANTIC_TYPE_LABELS,
} from '@/utils/statusLabels';
import { formatInt, formatPct } from '@/utils/format';
import { summarize } from '@/utils/profileSummary';
import { t } from '@/i18n';

export function ColumnProfileTable({ columns }: { columns: ColumnProfile[] }) {
  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table className="min-w-full text-sm">
        <caption className="sr-only">Column profile</caption>
        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th scope="col" className="px-4 py-3">
              {t('Column')}
            </th>
            <th scope="col" className="px-4 py-3">
              {t('Suggested meaning')}
            </th>
            <th scope="col" className="px-4 py-3">
              {t('Detected type')}
            </th>
            <th scope="col" className="px-4 py-3">
              {t('Missing')}
            </th>
            <th scope="col" className="px-4 py-3">
              {t('Summary')}
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {columns.map((c) => (
            <tr key={c.name}>
              <td className="px-4 py-3 font-medium text-slate-800 break-words max-w-xs">
                {c.name}
              </td>
              <td className="px-4 py-3 text-slate-700">
                {c.definition ? (
                  <>
                    <div className="font-medium">{c.definition.business_name}</div>
                    <div className="text-xs text-slate-500">
                      {t(SEMANTIC_TYPE_LABELS[c.definition.semantic_type])}
                      {c.definition.unit ? ` · ${c.definition.unit}` : ''}
                      {c.definition.aggregation
                        ? ` · ${t(AGGREGATION_LABELS[c.definition.aggregation])}`
                        : ''}
                    </div>
                    {c.definition.suggestion_reason ? (
                      <div className="mt-0.5 text-xs text-slate-600">
                        {c.definition.suggestion_reason}
                      </div>
                    ) : null}
                  </>
                ) : (
                  <span className="text-slate-600">—</span>
                )}
              </td>
              <td className="px-4 py-3 text-slate-600">
                {t(INFERRED_TYPE_LABELS[c.inferred_type])}
              </td>
              <td
                className={`px-4 py-3 tabular-nums ${c.null_rate >= 0.1 ? 'text-amber-700' : 'text-slate-600'}`}
              >
                {formatPct(c.null_rate)}{' '}
                <span className="text-slate-600">({formatInt(c.null_count)})</span>
              </td>
              <td className="px-4 py-3 text-slate-600">{summarize(c)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
