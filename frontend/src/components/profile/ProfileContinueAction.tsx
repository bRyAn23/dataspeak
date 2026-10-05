// FE-PRO-004 ProfileContinueAction: move to business definition after an acceptable profile.
import { ArrowRight } from 'lucide-react';
import { Button } from '@/components/layout/Button';
import { t } from '@/i18n';

export function ProfileContinueAction({
  onContinue,
  blocking,
}: {
  onContinue: () => void;
  blocking: boolean;
}) {
  return (
    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <p className="text-sm text-slate-600">
        {blocking
          ? t('Some columns cannot be analysed. You can still continue; they will be excluded.')
          : t('Next, tell Data Speak what each column means.')}
      </p>
      <Button onClick={onContinue} icon={<ArrowRight size={16} aria-hidden="true" />}>
        {t('Continue to definitions')}
      </Button>
    </div>
  );
}
