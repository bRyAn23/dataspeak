// FE-UPL-004 PrivacyNotice: explains transient raw-data handling.
import { ShieldCheck } from 'lucide-react';
import { t } from '@/i18n';

export function PrivacyNotice() {
  return (
    <div className="flex items-start gap-3 rounded-xl border border-brand-100 bg-brand-50 p-4 text-sm text-brand-900">
      <ShieldCheck size={18} className="mt-0.5 shrink-0 text-brand-700" aria-hidden="true" />
      <p>
        {t(
          'Your file is processed in a temporary location and deleted as soon as the analysis finishes, or after one hour if you stop part way. Only derived results such as column summaries, statistics and charts are kept. Raw rows are never stored, logged, or sent to the AI model.',
        )}
      </p>
    </div>
  );
}
