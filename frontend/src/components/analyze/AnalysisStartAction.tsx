// FE-ANL-001 AnalysisStartAction: start the deterministic analysis (RUN-API-001).
import { Play } from 'lucide-react';
import { Button } from '@/components/layout/Button';
import { t } from '@/i18n';

export function AnalysisStartAction({
  onStart,
  starting,
  label,
}: {
  onStart: () => void;
  starting: boolean;
  label?: string;
}) {
  return (
    <Button onClick={onStart} loading={starting} icon={<Play size={16} aria-hidden="true" />}>
      {label ?? t('Start analysis')}
    </Button>
  );
}
