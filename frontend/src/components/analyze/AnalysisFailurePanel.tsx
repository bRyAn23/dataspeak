// FE-ANL-004 AnalysisFailurePanel: safe error and retry action for FAILED analyses.
import { AlertTriangle, RotateCcw, Upload } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Button } from '@/components/layout/Button';
import type { LastError } from '@/types/analysis.types';
import { t } from '@/i18n';

const FRIENDLY: Record<string, string> = {
  ANALYTICS_UNAVAILABLE:
    'The analysis service was unavailable. Your file is kept for a short while so you can retry.',
  ANALYTICS_INSUFFICIENT_DATA:
    'There was not enough usable data for the analysis. Check the definitions or upload a larger file.',
  ANALYTICS_INVALID_DATASET: 'The dataset could not be processed. Please upload it again.',
  ANALYTICS_UNSUPPORTED_TYPE:
    'A column definition does not match its values. Review the definitions.',
  REPORT_GENERATION_FAILED: 'The report could not be assembled. You can try again.',
  JOB_INTERRUPTED:
    'This step stopped before it finished, for example because the background worker was restarted. Nothing already calculated is lost.',
};

interface Props {
  error: LastError | null;
  canRetry: boolean;
  onRetry: () => void;
  retrying: boolean;
  analysisId: string;
  /** The statistics are intact; only the report needs to be written again (RPT-API-001). */
  canRebuildReport?: boolean;
  onRebuildReport?: () => void;
  rebuilding?: boolean;
}

export function AnalysisFailurePanel({
  error,
  canRetry,
  onRetry,
  retrying,
  analysisId,
  canRebuildReport = false,
  onRebuildReport,
  rebuilding = false,
}: Props) {
  const message =
    error && FRIENDLY[error.code]
      ? t(FRIENDLY[error.code])
      : t('The analysis could not be completed.');
  return (
    <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-6">
      <div className="flex items-start gap-3">
        <AlertTriangle size={20} className="text-red-600 mt-0.5 shrink-0" aria-hidden="true" />
        <div className="flex-1">
          <p className="font-medium text-red-800">{t('Something went wrong')}</p>
          <p className="mt-1 text-sm text-red-700">{message}</p>
          {error && (
            <p className="mt-1 text-xs text-red-500">
              {t('Reference: {code}', { code: error.code })}
            </p>
          )}
          <div className="mt-4 flex flex-wrap gap-2">
            {canRebuildReport && onRebuildReport ? (
              <Button
                onClick={onRebuildReport}
                loading={rebuilding}
                icon={<RotateCcw size={16} aria-hidden="true" />}
              >
                {t('Build the report again')}
              </Button>
            ) : null}
            {canRebuildReport && !canRetry ? null : canRetry ? (
              <Button
                onClick={onRetry}
                loading={retrying}
                icon={<RotateCcw size={16} aria-hidden="true" />}
              >
                {t('Retry analysis')}
              </Button>
            ) : (
              <Link
                to={`/analyses/new?id=${analysisId}`}
                className="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-lg bg-brand-700 text-white hover:bg-brand-600"
              >
                <Upload size={16} aria-hidden="true" />
                {t('Upload the file again')}
              </Link>
            )}
            <Link
              to={`/analyses/${analysisId}/define`}
              className="inline-flex items-center px-4 py-2.5 text-sm font-medium rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
            >
              {t('Review definitions')}
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}
