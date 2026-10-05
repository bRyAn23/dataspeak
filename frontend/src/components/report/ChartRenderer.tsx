// FE-RPT-004 ChartRenderer: renders the backend visualization specification with Recharts.
// The frontend chooses nothing and computes nothing; unsupported types fall back to a table.
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import type { Visualization } from '@/types/report.types';
import { distinguishLabels, formatMeasure, truncateLabel } from '@/utils/format';
import { t } from '@/i18n';
import { themeColors } from '@/utils/chartThemes';

const AXIS = { fontSize: 11, fill: '#475569' };
const tooltipStyle = { fontSize: 12, border: '1px solid #E2E8F0', borderRadius: 8 };

function TableFallback({ spec }: { spec: Visualization }) {
  const keys = spec.series.length ? Object.keys(spec.series[0]) : [];
  if (keys.length === 0)
    return (
      <p className="text-sm text-slate-500">{t('No data points were provided for this chart.')}</p>
    );
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full text-sm">
        <caption className="sr-only">{spec.title}</caption>
        <thead className="text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            {keys.map((k) => (
              <th key={k} scope="col" className="px-3 py-2">
                {k === spec.x?.field
                  ? spec.x.label
                  : k === spec.y?.field
                    ? spec.y.label
                    : k.replace(/_/g, ' ')}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {spec.series.map((row, i) => (
            <tr key={i}>
              {keys.map((k) => (
                <td key={k} className="px-3 py-2 tabular-nums text-slate-700">
                  {typeof row[k] === 'number'
                    ? formatMeasure(row[k], k === spec.y?.field ? spec.y?.unit : null)
                    : String(row[k] ?? '—')}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function ChartRenderer({ spec, theme }: { spec: Visualization; theme?: string | null }) {
  // FEAT-VIS-004: the colour theme chosen at chart review; bars cycle through it, lines use its first colour.
  const colors = themeColors(theme);
  const primary = colors[0];
  const x = spec.x?.field ?? '';
  const y = spec.y?.field ?? '';
  const yLabel = spec.y?.label ?? '';
  const unit = spec.y?.unit ?? null;
  const tickFmt = (v: number) => formatMeasure(v, null);
  const tipFmt = (v: unknown) => [formatMeasure(Number(v), unit), yLabel] as [string, string];
  const hasData = spec.series.length > 0 && x && y;

  let body: React.ReactNode;
  if (!hasData) {
    body = <TableFallback spec={spec} />;
  } else if (spec.type === 'LINE') {
    body = (
      <ResponsiveContainer width="100%" height={240}>
        <LineChart data={spec.series} margin={{ top: 16, right: 24, left: 0, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" />
          <XAxis
            dataKey={x}
            tick={AXIS}
            axisLine={false}
            tickLine={false}
            minTickGap={24}
            tickFormatter={(v) => truncateLabel(v, 12)}
          />
          <YAxis tickFormatter={tickFmt} tick={AXIS} axisLine={false} tickLine={false} width={64} />
          <Tooltip
            formatter={tipFmt}
            labelFormatter={(label) => String(label)}
            contentStyle={tooltipStyle}
          />
          <Line
            type="monotone"
            dataKey={y}
            stroke={primary}
            strokeWidth={2}
            dot={false}
            activeDot={{ r: 4, fill: primary }}
          />
        </LineChart>
      </ResponsiveContainer>
    );
  } else if (spec.type === 'BAR' || spec.type === 'HISTOGRAM') {
    const data =
      spec.type === 'HISTOGRAM'
        ? spec.series.map((r) => ({
            ...r,
            [x]: `${formatMeasure(r.from as number, null)}–${formatMeasure(r.to as number, null)}`,
          }))
        : spec.series;
    // Category values can be long free text (e.g. an auction round title). Long or many labels are shortened
    // and angled so they never overlap; the tooltip shows the full value.
    const fullLabels = data.map((r) => String(r[x] ?? ''));
    const shortLabels = distinguishLabels(fullLabels);
    const tickFor = new Map(fullLabels.map((l, i) => [l, shortLabels[i]]));
    const longest = Math.max(0, ...shortLabels.map((l) => l.length));
    const angled = data.length > 6 || longest > 14;
    const maxChars = data.length > 8 ? 10 : angled ? 22 : 24;
    body = (
      <ResponsiveContainer width="100%" height={angled ? 300 : 240}>
        <BarChart data={data} margin={{ top: 16, right: 8, left: 0, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" />
          <XAxis
            dataKey={x}
            tick={AXIS}
            axisLine={false}
            tickLine={false}
            interval={0}
            angle={angled ? -30 : 0}
            textAnchor={angled ? 'end' : 'middle'}
            height={angled ? 88 : 30}
            tickFormatter={(v) => truncateLabel(tickFor.get(String(v)) ?? v, maxChars)}
          />
          <YAxis tickFormatter={tickFmt} tick={AXIS} axisLine={false} tickLine={false} width={64} />
          <Tooltip
            formatter={tipFmt}
            labelFormatter={(label) => String(label)}
            contentStyle={tooltipStyle}
          />
          <Bar dataKey={y} fill={primary} radius={[4, 4, 0, 0]}>
            {data.map((_, i) => (
              <Cell
                key={i}
                fill={spec.type === 'HISTOGRAM' ? primary : colors[i % colors.length]}
              />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    );
  } else if (spec.type === 'PIE') {
    // FEAT-VIS-005: share of the stored group totals; the slices are the data, the percentages are their ratio.
    const total = spec.series.reduce((acc, r) => acc + (Number(r[y]) || 0), 0) || 1;
    const pieTipFmt = (v: unknown, _name: unknown, item: { payload?: Record<string, unknown> }) =>
      [
        `${formatMeasure(Number(v), unit)} · ${((Number(v) / total) * 100).toFixed(1)}%`,
        String(item?.payload?.[x] ?? yLabel),
      ] as [string, string];
    body = (
      <ResponsiveContainer width="100%" height={260}>
        <PieChart margin={{ top: 8, right: 8, left: 8, bottom: 8 }}>
          <Pie
            data={spec.series}
            dataKey={y}
            nameKey={x}
            cx="50%"
            cy="50%"
            outerRadius={88}
            innerRadius={0}
            stroke="#FFFFFF"
            strokeWidth={1.5}
            isAnimationActive={false}
            label={({ percent }) =>
              (percent ?? 0) >= 0.06 ? `${((percent ?? 0) * 100).toFixed(0)}%` : ''
            }
            labelLine={false}
          >
            {spec.series.map((_, i) => (
              <Cell key={i} fill={colors[i % colors.length]} />
            ))}
          </Pie>
          <Tooltip formatter={pieTipFmt} contentStyle={tooltipStyle} />
          <Legend
            verticalAlign="middle"
            align="right"
            layout="vertical"
            iconType="circle"
            iconSize={8}
            wrapperStyle={{ fontSize: 11, maxWidth: '45%' }}
            formatter={(value) => truncateLabel(String(value), 24)}
          />
        </PieChart>
      </ResponsiveContainer>
    );
  } else {
    body = <TableFallback spec={spec} />;
  }

  return (
    <figure className="rounded-xl border border-slate-200 bg-white p-5">
      <figcaption className="mb-3 flex items-start justify-between gap-3">
        <div>
          <h3 className="text-sm font-semibold text-slate-800">{spec.title}</h3>
          <p className="text-xs text-slate-500">
            {spec.y?.label}
            {unit ? ` (${unit})` : ''}
            {spec.x?.label ? t(' by {label}', { label: spec.x.label }) : ''}
          </p>
        </div>
        {spec.unit_missing && (
          <span className="shrink-0 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">
            {t('Unit not defined')}
          </span>
        )}
      </figcaption>
      {body}
    </figure>
  );
}
