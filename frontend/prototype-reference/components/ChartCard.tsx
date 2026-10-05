import {
  BarChart,
  Bar,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Cell,
  LabelList,
} from 'recharts';
import type { ChartData } from '../types';

const BRAND = '#087A43';
const ACCENT = '#63BE45';
const SLATE = '#94A3B8';

const fmtRevenueY = (v: number) => {
  if (v === 0) return '฿0';
  if (v >= 1000000) return `฿${(v / 1000000).toFixed(1)}M`;
  if (v >= 1000) return `฿${(v / 1000).toFixed(0)}K`;
  return `฿${v}`;
};

const fmtRevenue = (v: unknown) => [`฿${(Number(v) / 1000000).toFixed(2)}M`, 'Revenue'] as const;
const fmtUnits = (v: unknown) => [`${Number(v)}%`, 'Share of units'] as const;

const tooltipStyle = {
  fontSize: 12,
  border: '1px solid #E2E8F0',
  borderRadius: 8,
  boxShadow: 'none',
};
const labelStyle = { fontSize: 10, fill: '#475569', fontFamily: 'Inter,sans-serif' };

// Custom bar label: ฿X.XXM above each bar
const RevenueBarLabel = (props: Record<string, unknown>) => {
  const x = Number(props.x),
    y = Number(props.y),
    width = Number(props.width),
    value = Number(props.value);
  return (
    <text
      x={x + width / 2}
      y={y - 5}
      textAnchor="middle"
      fontSize={10}
      fill="#475569"
      fontFamily="Inter,sans-serif"
    >
      {`฿${(value / 1e6).toFixed(2)}M`}
    </text>
  );
};

// Custom bar label: XX.X% to the right of each horizontal bar
const UnitsBarLabel = (props: Record<string, unknown>) => {
  const x = Number(props.x),
    y = Number(props.y),
    height = Number(props.height),
    value = Number(props.value);
  return (
    <text
      x={x + 4}
      y={y + height / 2 + 4}
      fontSize={10}
      fill="#475569"
      fontFamily="Inter,sans-serif"
    >
      {`${value}%`}
    </text>
  );
};

// Line chart dot + label only for first and last points
const LineEndDot = (dataLen: number) => (props: Record<string, unknown>) => {
  const cx = Number(props.cx),
    cy = Number(props.cy),
    index = Number(props.index),
    value = Number(props.value);
  if (index !== 0 && index !== dataLen - 1) return <g />;
  const isFirst = index === 0;
  return (
    <g>
      <circle cx={cx} cy={cy} r={4} fill={BRAND} stroke="white" strokeWidth={2} />
      <text
        x={cx}
        y={cy - 10}
        textAnchor={isFirst ? 'start' : 'end'}
        fontSize={10}
        fill="#475569"
        fontFamily="Inter,sans-serif"
      >
        {`฿${(value / 1e6).toFixed(2)}M`}
      </text>
    </g>
  );
};

export function ChartCard({ chart }: { chart: ChartData }) {
  if (chart.type === 'line') {
    const dataLen = chart.data.length;
    return (
      <ResponsiveContainer width="100%" height={240}>
        <LineChart data={chart.data} margin={{ top: 24, right: 48, left: 0, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" />
          <XAxis
            dataKey={chart.xKey}
            tick={{ fontSize: 11, fill: '#475569' }}
            axisLine={false}
            tickLine={false}
          />
          <YAxis
            tickFormatter={fmtRevenueY}
            tick={{ fontSize: 11, fill: '#475569' }}
            axisLine={false}
            tickLine={false}
            width={60}
          />
          <Tooltip formatter={fmtRevenue} contentStyle={tooltipStyle} />
          <Line
            type="monotone"
            dataKey={chart.yKey}
            stroke={BRAND}
            strokeWidth={2}
            dot={LineEndDot(dataLen)}
            activeDot={{ r: 4, fill: BRAND }}
          />
        </LineChart>
      </ResponsiveContainer>
    );
  }

  if (chart.type === 'horizontalBar') {
    return (
      <ResponsiveContainer width="100%" height={200}>
        <BarChart
          data={chart.data}
          layout="vertical"
          margin={{ top: 0, right: 56, left: 60, bottom: 0 }}
        >
          <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" horizontal={false} />
          <XAxis
            type="number"
            tickFormatter={(v) => `${v}%`}
            tick={{ fontSize: 11, fill: '#475569' }}
            axisLine={false}
            tickLine={false}
          />
          <YAxis
            type="category"
            dataKey={chart.yKey}
            tick={{ fontSize: 11, fill: '#475569' }}
            axisLine={false}
            tickLine={false}
          />
          <Tooltip formatter={fmtUnits} contentStyle={tooltipStyle} />
          <Bar dataKey={chart.xKey} radius={[0, 4, 4, 0]}>
            {chart.data.map((_entry, i) => (
              <Cell key={i} fill={i === 0 ? BRAND : ACCENT} />
            ))}
            <LabelList content={UnitsBarLabel as never} />
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    );
  }

  // Vertical revenue bar chart
  return (
    <ResponsiveContainer width="100%" height={240}>
      <BarChart data={chart.data} margin={{ top: 28, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" />
        <XAxis
          dataKey={chart.xKey}
          tick={{ fontSize: 11, fill: '#475569' }}
          axisLine={false}
          tickLine={false}
        />
        <YAxis
          tickFormatter={fmtRevenueY}
          tick={labelStyle}
          axisLine={false}
          tickLine={false}
          width={60}
        />
        <Tooltip formatter={fmtRevenue} contentStyle={tooltipStyle} />
        <Bar dataKey={chart.yKey} radius={[4, 4, 0, 0]}>
          {chart.data.map((entry, i) => {
            const key = chart.xKey === 'region' ? entry.region : entry.quarter;
            return <Cell key={i} fill={key === chart.highlight ? BRAND : SLATE} />;
          })}
          <LabelList content={RevenueBarLabel as never} />
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}
