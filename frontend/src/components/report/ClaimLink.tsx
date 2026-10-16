// FE-RPT-005 ClaimLink: opens evidence/traceability detail for a claim.
import { Link } from 'react-router-dom';
import { Link2 } from 'lucide-react';
import { t } from '@/i18n';

export function ClaimLink({
  analysisId,
  claimId,
  label,
  compact = false,
}: {
  analysisId: string;
  claimId: string;
  label?: string;
  /** Numbered superscript link in the executive summary: padded to a 24px minimum target (DEF-07). */
  compact?: boolean;
}) {
  return (
    <Link
      to={`/analyses/${analysisId}/report/claims/${claimId}`}
      aria-label={compact ? t('How we know, statement {n}', { n: label ?? '' }) : undefined}
      className={
        compact
          ? 'inline-flex min-h-6 min-w-6 items-center justify-center gap-0.5 rounded px-1 text-xs font-semibold text-brand-700 hover:bg-brand-50 hover:underline focus:outline-none focus:ring-2 focus:ring-brand-700'
          : 'inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline focus:outline-none focus:ring-2 focus:ring-brand-700 rounded'
      }
    >
      <Link2 size={12} aria-hidden="true" />
      {label ?? t('How we know')}
    </Link>
  );
}
