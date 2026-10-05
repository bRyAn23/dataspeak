// FE-RPT-007 ExportPdfAction: downloads the server-rendered PDF (RPT-API-003 / FEAT-RPT-003).
// The browser never renders the PDF itself; Laravel builds it from the same verified report data.
import { useState } from 'react';
import { FileDown, Loader2 } from 'lucide-react';
import { describeError } from '@/services/apiClient';
import { reportService } from '@/services/report.service';
import { t } from '@/i18n';

interface Props {
  analysisId: string;
}

export function ExportPdfAction({ analysisId }: Props) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const download = async () => {
    setBusy(true);
    setError(null);
    try {
      const { blob, fileName } = await reportService.pdf(analysisId);
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = fileName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      setError(describeError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <span className="inline-flex flex-col items-end gap-1">
      <button
        type="button"
        onClick={download}
        disabled={busy}
        className="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 disabled:opacity-60 disabled:cursor-wait"
      >
        {busy ? (
          <Loader2 size={16} className="animate-spin" aria-hidden="true" />
        ) : (
          <FileDown size={16} aria-hidden="true" />
        )}
        {busy ? t('Preparing PDF…') : t('Export PDF')}
      </button>
      {error ? (
        <span role="alert" className="text-xs text-red-600">
          {error}
        </span>
      ) : null}
    </span>
  );
}
