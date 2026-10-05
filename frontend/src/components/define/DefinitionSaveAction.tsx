// FE-DEF-004 DefinitionSaveAction: persist definitions through Laravel and move on.
import { ArrowRight } from 'lucide-react';
import { Button } from '@/components/layout/Button';
import { t } from '@/i18n';

export function DefinitionSaveAction({
  onSave,
  saving,
  formError,
}: {
  onSave: () => void;
  saving: boolean;
  formError?: string;
}) {
  return (
    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <p
        className={`text-sm ${formError ? 'text-red-700' : 'text-slate-600'}`}
        role={formError ? 'alert' : undefined}
      >
        {formError ??
          t('Definitions decide which columns are measured and how they are labelled in charts.')}
      </p>
      <Button onClick={onSave} loading={saving} icon={<ArrowRight size={16} aria-hidden="true" />}>
        {t('Save and continue')}
      </Button>
    </div>
  );
}
