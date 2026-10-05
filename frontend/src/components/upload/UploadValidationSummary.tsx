// FE-UPL-003 UploadValidationSummary: validation success, warnings and errors from DATA-API-002.
import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-react';
import type { ValidateResponse } from '@/types/dataset.types';
import { formatInt } from '@/utils/format';
import { t } from '@/i18n';

export function UploadValidationSummary({ result }: { result: ValidateResponse }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 text-sm" aria-live="polite">
      <div className="flex items-center gap-2 font-medium text-slate-800">
        {result.valid ? (
          <CheckCircle2 size={18} className="text-emerald-600" aria-hidden="true" />
        ) : (
          <XCircle size={18} className="text-red-600" aria-hidden="true" />
        )}
        {result.valid ? t('File checked successfully') : t('The file could not be used')}
      </div>
      {result.valid && (
        <p className="mt-1 text-slate-600">
          {t('{rows} rows and {columns} columns detected.', {
            rows: formatInt(result.row_count),
            columns: formatInt(result.column_count),
          })}
        </p>
      )}
      {result.errors.length > 0 && (
        <ul className="mt-2 space-y-1">
          {result.errors.map((e, i) => (
            <li key={i} className="flex items-start gap-2 text-red-700">
              <XCircle size={14} className="mt-0.5 shrink-0" aria-hidden="true" />
              <span>
                {e.column ? <span className="font-medium">{e.column}: </span> : null}
                {e.message}
              </span>
            </li>
          ))}
        </ul>
      )}
      {result.warnings.length > 0 && (
        <ul className="mt-2 space-y-1">
          {result.warnings.map((w, i) => (
            <li key={i} className="flex items-start gap-2 text-amber-700">
              <AlertTriangle size={14} className="mt-0.5 shrink-0" aria-hidden="true" />
              <span>
                {w.column ? <span className="font-medium">{w.column}: </span> : null}
                {w.message}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
