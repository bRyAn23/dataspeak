// FE-CMP-007 ErrorState: recoverable error presentation with a safe message and optional retry.
import { AlertTriangle } from 'lucide-react';
import { ApiError, describeError } from '@/services/apiClient';
import { t } from '@/i18n';

interface Props {
  error: unknown;
  onRetry?: () => void;
  title?: string;
}

export function ErrorState({ error, onRetry, title }: Props) {
  const code = error instanceof ApiError ? error.code : undefined;
  return (
    <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-5 text-sm">
      <div className="flex items-start gap-3">
        <AlertTriangle size={18} className="text-red-600 mt-0.5 shrink-0" aria-hidden="true" />
        <div className="flex-1">
          <p className="font-medium text-red-800">{title ?? t('Something needs attention')}</p>
          <p className="mt-1 text-red-700">{describeError(error)}</p>
          {code && <p className="mt-1 text-xs text-red-500">{t('Reference: {code}', { code })}</p>}
          {onRetry && (
            <button
              type="button"
              onClick={onRetry}
              className="mt-3 px-3 py-1.5 rounded-lg bg-white border border-red-300 text-red-700 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500"
            >
              {t('Try again')}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
