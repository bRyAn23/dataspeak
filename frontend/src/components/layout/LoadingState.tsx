// FE-CMP-006 LoadingState: consistent loading feedback.
import { Loader2 } from 'lucide-react';
import { t } from '@/i18n';

export function LoadingState({ label }: { label?: string }) {
  return (
    <div
      role="status"
      aria-live="polite"
      className="flex items-center justify-center gap-2 py-16 text-sm text-slate-500"
    >
      <Loader2 size={18} className="animate-spin" aria-hidden="true" />
      <span>{label ?? t('Loading…')}</span>
    </div>
  );
}
