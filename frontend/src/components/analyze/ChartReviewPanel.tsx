// FE-ANL-005 ChartReviewPanel: after the calculations, the recommended charts are shown with a preview; the user
// keeps or removes them and can add any other chart the calculated evidence supports (VIS-API-002/003).
// Two tabs: the system's recommended charts, and the custom charts the user requires. The executive summary is
// written from the kept set; the colour theme (FEAT-VIS-004) applies to every chart.
import { useMemo, useState } from 'react';
import { BarChart3, Check, Palette, Plus, Sparkles, Trash2, Wand2 } from 'lucide-react';
import { useChartOptions, useSelectCharts, useVisualizations } from '@/hooks/useAnalyses';
import { Button } from '@/components/layout/Button';
import { LoadingState } from '@/components/layout/LoadingState';
import { ErrorState } from '@/components/layout/ErrorState';
import { ChartRenderer } from '@/components/report/ChartRenderer';
import { describeError } from '@/services/apiClient';
import { t } from '@/i18n';
import type { ChartOption, ChartType, Visualization } from '@/types/report.types';
import {
  CHART_THEMES,
  DEFAULT_CHART_THEME,
  isChartTheme,
  type ChartThemeKey,
} from '@/utils/chartThemes';

/** An option drawn exactly like a saved chart. */
function asChart(o: ChartOption, position: number): Visualization {
  return {
    id: `option:${o.key}`,
    type: o.type,
    title: o.title,
    x: o.x,
    y: o.y,
    unit_missing: o.unit_missing,
    series: o.series,
    evidence_ids: o.evidence_ids,
    insight_rank: o.insight_rank,
    position,
    source: 'USER',
    is_selected: true,
    reason: o.reason,
  };
}

const TYPE_LABELS: Record<ChartType, string> = {
  LINE: 'Line chart',
  BAR: 'Bar chart',
  SCATTER: 'Scatter chart',
  HISTOGRAM: 'Histogram',
  TABLE: 'Table',
  PIE: 'Pie chart',
};

type Tab = 'recommended' | 'custom';

interface Props {
  analysisId: string;
  /** Theme saved on the analysis (FEAT-VIS-004); the default until the user picks another. */
  initialTheme?: string | null;
  /** Called once the selection is saved; the parent then starts the report. */
  onConfirmed: () => void;
  confirming: boolean;
  confirmLabel: string;
}

export function ChartReviewPanel({
  analysisId,
  initialTheme,
  onConfirmed,
  confirming,
  confirmLabel,
}: Props) {
  const charts = useVisualizations(analysisId);
  const options = useChartOptions(analysisId);
  const select = useSelectCharts(analysisId);
  const [tab, setTab] = useState<Tab>('recommended');
  const [kept, setKept] = useState<Set<string> | null>(null);
  const [added, setAdded] = useState<string[]>([]);
  const [pick, setPick] = useState('');
  const [theme, setTheme] = useState<ChartThemeKey>(() =>
    isChartTheme(initialTheme) ? initialTheme : DEFAULT_CHART_THEME,
  );
  const [error, setError] = useState<string | null>(null);

  // Start from the recommendation: everything the system kept is ticked until the user changes it.
  const effectiveKept = useMemo(
    () => kept ?? new Set((charts.data ?? []).filter((c) => c.is_selected).map((c) => c.id)),
    [kept, charts.data],
  );
  const recommended = useMemo(
    () => (charts.data ?? []).filter((c) => c.source !== 'USER'),
    [charts.data],
  );
  // Custom charts saved on an earlier review (source USER) plus the ones added in this session.
  const savedCustom = useMemo(
    () => (charts.data ?? []).filter((c) => c.source === 'USER'),
    [charts.data],
  );
  const available = useMemo(
    () => (options.data ?? []).filter((o) => !added.includes(o.key)),
    [options.data, added],
  );
  const keptCount = effectiveKept.size + added.length;
  const recommendedKept = recommended.filter((c) => effectiveKept.has(c.id)).length;
  const customCount = savedCustom.filter((c) => effectiveKept.has(c.id)).length + added.length;
  const picked = useMemo(
    () => options.data?.find((o) => o.key === pick) ?? null,
    [options.data, pick],
  );

  const toggle = (id: string) =>
    setKept(() => {
      const next = new Set(effectiveKept);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const confirm = async () => {
    setError(null);
    try {
      await select.mutateAsync({ selected: [...effectiveKept], add: added, theme });
      setAdded([]);
      setKept(null);
      onConfirmed();
    } catch (e) {
      setError(describeError(e));
    }
  };

  if (charts.isLoading || options.isLoading) return <LoadingState label={t('Loading charts…')} />;
  if (charts.isError) return <ErrorState error={charts.error} onRetry={() => charts.refetch()} />;

  const savedCard = (c: Visualization) => {
    const on = effectiveKept.has(c.id);
    return (
      <li
        key={c.id}
        className={`rounded-xl border p-3 ${on ? 'border-brand-300 bg-white' : 'border-slate-200 bg-slate-50 opacity-70'}`}
      >
        <label className="flex items-start gap-3 text-sm">
          <input
            type="checkbox"
            checked={on}
            onChange={() => toggle(c.id)}
            className="mt-1 h-4 w-4 accent-brand-700"
            aria-label={t('Keep {title}', { title: c.title })}
          />
          <span className="flex-1">
            <span className="font-medium text-slate-900">{c.title}</span>
            <span className="ml-2 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-600">
              {t(TYPE_LABELS[c.type])}
            </span>
            <span
              className={`ml-1 rounded px-1.5 py-0.5 text-xs ${c.source === 'USER' ? 'bg-violet-50 text-violet-700' : 'bg-emerald-50 text-emerald-700'}`}
            >
              {c.source === 'USER' ? t('Added by you') : t('Recommended')}
            </span>
          </span>
        </label>
        {c.reason ? <p className="mt-1 pl-7 text-xs text-slate-600">{c.reason}</p> : null}
        <div className="mt-2 pointer-events-none text-[11px]">
          <ChartRenderer spec={c} theme={theme} />
        </div>
      </li>
    );
  };

  const tabs: { key: Tab; label: string; count: number; icon: React.ReactNode }[] = [
    {
      key: 'recommended',
      label: t('Recommended charts'),
      count: recommendedKept,
      icon: <Wand2 size={15} aria-hidden="true" />,
    },
    {
      key: 'custom',
      label: t('Custom required charts'),
      count: customCount,
      icon: <Plus size={15} aria-hidden="true" />,
    },
  ];

  return (
    <section
      aria-labelledby="chart-review-h"
      className="rounded-xl border border-slate-200 bg-white p-6 space-y-5"
    >
      <div>
        <h2
          id="chart-review-h"
          className="flex items-center gap-2 text-lg font-semibold text-slate-900"
        >
          <BarChart3 size={18} className="text-brand-700" aria-hidden="true" />
          {t('Choose the charts for your report')}
        </h2>
        <p className="mt-1 text-sm text-slate-600">
          {t(
            'The calculations are done. Keep the charts recommended from the results, add the ones you require, and pick a colour theme. The executive summary is written from the charts you keep.',
          )}
        </p>
      </div>

      <fieldset className="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <legend className="flex items-center gap-2 px-1 text-sm font-medium text-slate-800">
          <Palette size={16} className="text-brand-700" aria-hidden="true" />
          {t('Chart colours')}
        </legend>
        <p className="text-xs text-slate-500">
          {t('Applies to every chart in the report and the PDF.')}
        </p>
        <div className="mt-3 flex flex-wrap gap-2">
          {CHART_THEMES.map((th) => {
            const on = th.key === theme;
            return (
              <label
                key={th.key}
                className={`flex cursor-pointer items-center gap-2 rounded-lg border px-2.5 py-1.5 text-sm ${on ? 'border-brand-700 bg-white ring-2 ring-brand-100' : 'border-slate-200 bg-white hover:border-slate-300'}`}
              >
                <input
                  type="radio"
                  name="chart-theme"
                  value={th.key}
                  checked={on}
                  onChange={() => setTheme(th.key)}
                  className="sr-only"
                />
                <span className="flex gap-0.5" aria-hidden="true">
                  {th.colors.slice(0, 5).map((c) => (
                    <span key={c} className="h-4 w-2.5 rounded-sm" style={{ backgroundColor: c }} />
                  ))}
                </span>
                <span className={on ? 'font-medium text-slate-900' : 'text-slate-700'}>
                  {t(th.label)}
                </span>
              </label>
            );
          })}
        </div>
      </fieldset>

      <div
        role="tablist"
        aria-label={t('Chart type selection')}
        className="flex gap-1 border-b border-slate-200"
      >
        {tabs.map((tb) => {
          const on = tab === tb.key;
          return (
            <button
              key={tb.key}
              type="button"
              role="tab"
              id={`chart-tab-${tb.key}`}
              aria-selected={on}
              aria-controls={`chart-panel-${tb.key}`}
              onClick={() => setTab(tb.key)}
              className={`-mb-px flex items-center gap-2 rounded-t-lg border-b-2 px-4 py-2.5 text-sm font-medium ${on ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-600 hover:text-slate-900'}`}
            >
              {tb.icon}
              {tb.label}
              <span
                className={`rounded-full px-2 py-0.5 text-xs ${on ? 'bg-brand-50 text-brand-800' : 'bg-slate-100 text-slate-600'}`}
              >
                {tb.count}
              </span>
            </button>
          );
        })}
      </div>

      {tab === 'recommended' && (
        <div
          role="tabpanel"
          id="chart-panel-recommended"
          aria-labelledby="chart-tab-recommended"
          className="space-y-3"
        >
          <p className="text-sm text-slate-600">
            {t(
              'Charts recommended from the calculated results. Untick any you do not want in the report.',
            )}
          </p>
          {recommended.length === 0 ? (
            <p className="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">
              {t(
                'No charts were recommended for this dataset. Add the ones you require in the next tab.',
              )}
            </p>
          ) : (
            <ul className="grid gap-4 lg:grid-cols-2">{recommended.map(savedCard)}</ul>
          )}
        </div>
      )}

      {tab === 'custom' && (
        <div
          role="tabpanel"
          id="chart-panel-custom"
          aria-labelledby="chart-tab-custom"
          className="space-y-4"
        >
          <div className="rounded-lg border border-dashed border-slate-300 p-4">
            <p className="text-sm font-medium text-slate-800">{t('Add another chart')}</p>
            <p className="text-xs text-slate-500">
              {t(
                'Only charts that can be drawn from the calculated results are offered; nothing is computed in the browser.',
              )}
            </p>
            <div className="mt-3 flex flex-col gap-2 sm:flex-row">
              <label htmlFor="chart-option" className="sr-only">
                {t('Chart to add')}
              </label>
              <select
                id="chart-option"
                value={pick}
                onChange={(e) => setPick(e.target.value)}
                disabled={available.length === 0}
                className="flex-1 rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-700 disabled:bg-slate-50"
              >
                <option value="">
                  {available.length === 0 ? t('No more charts can be added') : t('Choose a chart…')}
                </option>
                {available.map((o) => (
                  <option key={o.key} value={o.key}>
                    {t(TYPE_LABELS[o.type])} · {o.title}
                  </option>
                ))}
              </select>
              <Button
                variant="secondary"
                disabled={!pick}
                onClick={() => {
                  if (!pick) return;
                  setAdded((prev) => [...prev, pick]);
                  setPick('');
                }}
                icon={<Plus size={16} aria-hidden="true" />}
              >
                {t('Add chart')}
              </Button>
            </div>
            {picked && (
              <div
                className="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3"
                aria-live="polite"
              >
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {t('Preview with your data')}
                </p>
                {picked.reason ? (
                  <p className="mb-2 text-xs text-slate-600">{picked.reason}</p>
                ) : null}
                <div className="pointer-events-none text-[11px]">
                  <ChartRenderer spec={asChart(picked, 99)} theme={theme} />
                </div>
              </div>
            )}
          </div>

          {savedCustom.length === 0 && added.length === 0 ? (
            <p className="text-sm text-slate-500">
              {t('No custom charts yet. Choose one above to see it drawn with your data.')}
            </p>
          ) : (
            <ul className="grid gap-4 lg:grid-cols-2">
              {savedCustom.map(savedCard)}
              {added.map((key, i) => {
                const o = options.data?.find((x) => x.key === key);
                if (!o) return null;
                return (
                  <li key={key} className="rounded-xl border border-violet-300 bg-white p-3">
                    <div className="flex items-start gap-3 text-sm">
                      <Check
                        size={16}
                        className="mt-1 shrink-0 text-violet-700"
                        aria-hidden="true"
                      />
                      <span className="flex-1">
                        <span className="font-medium text-slate-900">{o.title}</span>
                        <span className="ml-2 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-600">
                          {t(TYPE_LABELS[o.type])}
                        </span>
                        <span className="ml-1 rounded bg-violet-50 px-1.5 py-0.5 text-xs text-violet-700">
                          {t('Added by you')}
                        </span>
                      </span>
                      <button
                        type="button"
                        onClick={() => setAdded((prev) => prev.filter((k) => k !== key))}
                        className="rounded p-1 text-slate-500 hover:bg-red-50 hover:text-red-700"
                        aria-label={t('Remove {title}', { title: o.title })}
                      >
                        <Trash2 size={14} aria-hidden="true" />
                      </button>
                    </div>
                    {o.reason ? (
                      <p className="mt-1 pl-7 text-xs text-slate-600">{o.reason}</p>
                    ) : null}
                    <div className="mt-2 pointer-events-none text-[11px]">
                      <ChartRenderer
                        spec={asChart(o, (charts.data?.length ?? 0) + i)}
                        theme={theme}
                      />
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}

      {error ? (
        <p role="alert" className="text-sm text-red-700">
          {error}
        </p>
      ) : null}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-slate-600">
          {keptCount === 0
            ? t('Keep or add at least one chart.')
            : t('{n} charts will go into the report.', { n: keptCount })}
        </p>
        <Button
          onClick={confirm}
          disabled={keptCount === 0}
          loading={select.isPending || confirming}
          icon={<Sparkles size={16} aria-hidden="true" />}
        >
          {confirmLabel}
        </Button>
      </div>
    </section>
  );
}
