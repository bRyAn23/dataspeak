// FE-UPL-001 CsvDropzone: drag/drop and file picker for CSV. The File lives in component state only
// until upload; its contents are never read, logged or stored in the browser.
import { useRef, useState, type DragEvent } from 'react';
import { FileUp, X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { formatBytes } from '@/utils/format';
import { MAX_UPLOAD_MB as MAX_MB, acceptFile } from '@/utils/files';
import { t } from '@/i18n';

interface Props {
  file: File | null;
  onChange: (file: File | null) => void;
  disabled?: boolean;
}

export function CsvDropzone({ file, onChange, disabled }: Props) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const pick = (f: File | undefined) => {
    if (!f) return;
    const problem = acceptFile(f);
    setError(problem);
    onChange(problem ? null : f);
  };

  const onDrop = (e: DragEvent) => {
    e.preventDefault();
    setDragging(false);
    if (disabled) return;
    pick(e.dataTransfer.files?.[0]);
  };

  return (
    <div>
      <div
        role="button"
        tabIndex={disabled ? -1 : 0}
        aria-label={t('Choose a CSV or Excel file')}
        aria-disabled={disabled}
        onClick={() => !disabled && inputRef.current?.click()}
        onKeyDown={(e) => {
          if (!disabled && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            inputRef.current?.click();
          }
        }}
        onDragOver={(e) => {
          e.preventDefault();
          if (!disabled) setDragging(true);
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={onDrop}
        className={cn(
          'rounded-xl border-2 border-dashed p-8 text-center transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2',
          dragging
            ? 'border-brand-500 bg-brand-50'
            : 'border-slate-300 bg-white hover:border-brand-400',
          disabled && 'opacity-60 cursor-not-allowed',
        )}
      >
        <input
          ref={inputRef}
          type="file"
          accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          className="hidden"
          onChange={(e) => pick(e.target.files?.[0])}
          disabled={disabled}
          data-testid="csv-input"
        />
        <FileUp size={28} className="mx-auto text-brand-700" aria-hidden="true" />
        <p className="mt-3 text-sm font-medium text-slate-800">
          {t('Drop your CSV or Excel file here or click to choose')}
        </p>
        <p className="mt-1 text-xs text-slate-500">
          {t(
            'CSV or Excel (.xlsx), up to {mb} MB. For Excel the worksheet with the most data is used. One dataset per analysis.',
            { mb: MAX_MB },
          )}
        </p>
      </div>
      {file && (
        <div className="mt-3 flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
          <span className="truncate">
            <span className="font-medium text-slate-800">{file.name}</span>
            <span className="ml-2 text-slate-500">{formatBytes(file.size)}</span>
          </span>
          <button
            type="button"
            onClick={() => onChange(null)}
            className="p-1 rounded hover:bg-slate-100"
            aria-label={t('Remove file')}
            disabled={disabled}
          >
            <X size={14} aria-hidden="true" />
          </button>
        </div>
      )}
      {error && (
        <p role="alert" className="mt-2 text-sm text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}
