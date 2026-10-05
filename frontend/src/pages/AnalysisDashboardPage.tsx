// FE-PG-003 AnalysisDashboardPage: list analyses with status and actions (ANL-API-001/004).
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowRight, FileText, Plus, Trash2 } from 'lucide-react';
import { useAnalysesList, useDeleteAnalysis } from '@/hooks/useAnalyses';
import { PageHeader } from '@/components/layout/PageHeader';
import { StatusBadge } from '@/components/layout/StatusBadge';
import { LoadingState } from '@/components/layout/LoadingState';
import { ErrorState } from '@/components/layout/ErrorState';
import { EmptyState } from '@/components/layout/EmptyState';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { Button } from '@/components/layout/Button';
import { continuePath } from '@/utils/steps';
import { formatDate, formatInt } from '@/utils/format';
import type { Analysis } from '@/types/analysis.types';
import { t } from '@/i18n';

export function AnalysisDashboardPage() {
  const [page, setPage] = useState(1);
  const query = useAnalysesList(page);
  const remove = useDeleteAnalysis();
  const navigate = useNavigate();
  const [pending, setPending] = useState<Analysis | null>(null);

  const open = (a: Analysis) => navigate(continuePath(a.id, a.status));

  return (
    <>
      <PageHeader
        title={t('Analyses')}
        subtitle={t('Each analysis turns one CSV into verified findings and an executive report.')}
        actions={
          <Link
            to="/analyses/new"
            className="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-lg bg-brand-700 text-white hover:bg-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
          >
            <Plus size={16} aria-hidden="true" />
            {t('New analysis')}
          </Link>
        }
      />
      {query.isLoading && <LoadingState label={t('Loading analyses…')} />}
      {query.isError && <ErrorState error={query.error} onRetry={() => query.refetch()} />}
      {query.data && query.data.data.length === 0 && (
        <EmptyState
          title={t('No analyses yet')}
          body={t(
            'Upload a CSV file and Data Speak will profile it, verify the numbers, and write you a trustworthy report.',
          )}
          action={
            <Button onClick={() => navigate('/analyses/new')}>
              {t('Start your first analysis')}
            </Button>
          }
        />
      )}
      {query.data && query.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="min-w-full text-sm">
            <caption className="sr-only">{t('Your analyses')}</caption>
            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th scope="col" className="px-4 py-3">
                  {t('Analysis')}
                </th>
                <th scope="col" className="px-4 py-3">
                  {t('Status')}
                </th>
                <th scope="col" className="px-4 py-3">
                  {t('Rows')}
                </th>
                <th scope="col" className="px-4 py-3">
                  {t('Created')}
                </th>
                <th scope="col" className="px-4 py-3">
                  <span className="sr-only">{t('Actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {query.data.data.map((a) => (
                <tr key={a.id} className="hover:bg-slate-50 focus-within:bg-slate-50">
                  <td className="px-4 py-3">
                    {/* Row is clickable through its primary link; inline actions stay separate controls. */}
                    <Link
                      to={continuePath(a.id, a.status)}
                      className="font-medium text-slate-900 hover:underline focus:outline-none focus:ring-2 focus:ring-brand-700 rounded"
                    >
                      {a.name}
                    </Link>
                    <div className="text-xs text-slate-500">
                      {a.dataset.file_name ?? t('No file yet')}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge status={a.status} />
                  </td>
                  <td className="px-4 py-3 tabular-nums text-slate-600">
                    {formatInt(a.dataset.row_count)}
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    <time dateTime={a.created_at ?? undefined}>{formatDate(a.created_at)}</time>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-end gap-1">
                      {a.status === 'COMPLETED' ? (
                        <Button
                          variant="ghost"
                          className="px-2 py-1.5"
                          onClick={() => navigate(`/analyses/${a.id}/report`)}
                          icon={<FileText size={14} aria-hidden="true" />}
                          aria-label={t('Open report for {name}', { name: a.name })}
                        >
                          {t('Report')}
                        </Button>
                      ) : (
                        <Button
                          variant="ghost"
                          className="px-2 py-1.5"
                          onClick={() => open(a)}
                          icon={<ArrowRight size={14} aria-hidden="true" />}
                          aria-label={t('Continue {name}', { name: a.name })}
                        >
                          {t('Continue')}
                        </Button>
                      )}
                      <Button
                        variant="ghost"
                        className="px-2 py-1.5 text-red-600 hover:bg-red-50"
                        onClick={() => setPending(a)}
                        icon={<Trash2 size={14} aria-hidden="true" />}
                        aria-label={t('Delete {name}', { name: a.name })}
                      >
                        {t('Delete')}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {query.data.meta.last_page > 1 && (
            <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-600">
              <span>
                {t('Page {current} of {last}', {
                  current: query.data.meta.current_page,
                  last: query.data.meta.last_page,
                })}
              </span>
              <div className="flex gap-2">
                <Button
                  variant="secondary"
                  className="px-3 py-1.5"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => p - 1)}
                >
                  {t('Previous')}
                </Button>
                <Button
                  variant="secondary"
                  className="px-3 py-1.5"
                  disabled={page >= query.data.meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                >
                  {t('Next')}
                </Button>
              </div>
            </div>
          )}
        </div>
      )}
      <ConfirmDialog
        open={pending !== null}
        title={t('Delete this analysis?')}
        body={t('"{name}" and all of its derived results will be removed. This cannot be undone.', {
          name: pending?.name ?? '',
        })}
        confirmLabel={t('Delete')}
        danger
        busy={remove.isPending}
        onCancel={() => setPending(null)}
        onConfirm={() =>
          pending && remove.mutate(pending.id, { onSettled: () => setPending(null) })
        }
      />
    </>
  );
}
