// FE-RPT-005 ClaimLink: opens evidence/traceability detail for a claim.
import { Link } from 'react-router-dom';
import { Link2 } from 'lucide-react';
import { t } from '@/i18n';

export function ClaimLink({
  analysisId,
  claimId,
  label,
}: {
  analysisId: string;
  claimId: string;
  label?: string;
}) {
  return (
    <Link
      to={`/analyses/${analysisId}/report/claims/${claimId}`}
      className="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline focus:outline-none focus:ring-2 focus:ring-brand-700 rounded"
    >
      <Link2 size={12} aria-hidden="true" />
      {label ?? t('How we know')}
    </Link>
  );
}
