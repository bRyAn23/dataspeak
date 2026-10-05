// Shown when the whole pipeline has finished: a short, friendly completion moment before the report opens.
import { useEffect, useState } from 'react';
import { CheckCircle2, ArrowRight } from 'lucide-react';
import { Button } from '@/components/layout/Button';
import { t } from '@/i18n';

interface Props {
  analysisName: string;
  onContinue: () => void;
  /** Seconds before the report opens by itself. */
  autoAfterSeconds?: number;
}

export function CompletionCelebration({ analysisName, onContinue, autoAfterSeconds = 3 }: Props) {
  const [left, setLeft] = useState(autoAfterSeconds);

  useEffect(() => {
    if (left <= 0) {
      onContinue();
      return;
    }
    const t = setTimeout(() => setLeft((n) => n - 1), 1000);
    return () => clearTimeout(t);
  }, [left, onContinue]);

  return (
    <div
      role="status"
      aria-live="polite"
      className="rounded-xl border border-emerald-200 bg-emerald-50 p-10 text-center"
    >
      <div className="celebrate-pop mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-brand-700 text-white shadow-lg">
        <CheckCircle2 size={44} aria-hidden="true" />
      </div>
      <h2 className="mt-5 text-2xl font-semibold text-slate-900">
        {t('All done. Your report is ready.')}
      </h2>
      <p className="mt-2 text-sm text-slate-600">
        {t(
          '{name} has been checked, analysed and written up. Every number in the report comes from the calculations, and every sentence was verified against them.',
          { name: analysisName },
        )}
      </p>
      <div className="mt-6 flex items-center justify-center gap-3">
        <Button onClick={onContinue} icon={<ArrowRight size={16} aria-hidden="true" />}>
          {t('Open the report')}
        </Button>
        <span className="text-xs text-slate-500">{t('Opening in {s}s…', { s: left })}</span>
      </div>
    </div>
  );
}
