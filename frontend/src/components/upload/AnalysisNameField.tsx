// FE-UPL-002 AnalysisNameField: analysis name, auto-filled from the file name until edited.
import { t } from '@/i18n';

interface Props {
  value: string;
  onChange: (value: string) => void;
  error?: string;
  disabled?: boolean;
}

export function AnalysisNameField({ value, onChange, error, disabled }: Props) {
  return (
    <div>
      <label htmlFor="analysis-name" className="block text-sm font-medium text-slate-700 mb-1.5">
        {t('Analysis name')}
      </label>
      <input
        id="analysis-name"
        type="text"
        value={value}
        maxLength={255}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={Boolean(error)}
        aria-describedby={error ? 'analysis-name-error' : undefined}
        className="w-full px-3.5 py-2.5 text-sm rounded-lg border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-brand-700 disabled:bg-slate-50"
        placeholder={t('September Sales Analysis')}
      />
      {error && (
        <p id="analysis-name-error" className="mt-1 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}
