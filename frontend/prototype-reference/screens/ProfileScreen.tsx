import { useState, useRef } from 'react';
import {
  AlertTriangle,
  ChevronDown,
  ChevronUp,
  ChevronRight,
  Hash,
  AlignLeft,
  Tag,
  Calendar,
  BarChart2,
  Info,
} from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer } from 'recharts';
import { StatCard } from '../components/StatCard';

interface Props {
  analysisId: string;
  analysisName: string;
  onNext: () => void;
  onBack: () => void;
}

type ColType = 'Number' | 'Text' | 'Category' | 'Date';

interface ColProfile {
  name: string;
  type: ColType;
  present: number; // 0–100 %
  distinct: number;
  summary: React.ReactNode;
  miniData: number[]; // 5 relative bar heights 0–100
  distribution: { label: string; value: number }[];
  topValues: { label: string; count: number }[];
}

const TypeIcon: Record<ColType, React.ElementType> = {
  Number: Hash,
  Text: AlignLeft,
  Category: Tag,
  Date: Calendar,
};
const TypeColor: Record<ColType, string> = {
  Number: 'bg-blue-50 text-blue-700 border-blue-200',
  Text: 'bg-slate-100 text-slate-700 border-slate-200',
  Category: 'bg-violet-50 text-violet-700 border-violet-200',
  Date: 'bg-amber-50 text-amber-700 border-amber-200',
};

const COLS: ColProfile[] = [
  {
    name: 'order_date',
    type: 'Date',
    present: 100,
    distinct: 365,
    summary: <span className="text-xs text-slate-700">1 Jan – 31 Dec 2025</span>,
    miniData: [20, 22, 25, 24, 22, 23, 21, 22, 23, 28, 30, 26],
    distribution: [
      { label: 'Jan', value: 4012 },
      { label: 'Feb', value: 3890 },
      { label: 'Mar', value: 4234 },
      { label: 'Apr', value: 3980 },
      { label: 'May', value: 4102 },
      { label: 'Jun', value: 4001 },
      { label: 'Jul', value: 3956 },
      { label: 'Aug', value: 4088 },
      { label: 'Sep', value: 3941 },
      { label: 'Oct', value: 4210 },
      { label: 'Nov', value: 4101 },
      { label: 'Dec', value: 3700 },
    ],
    topValues: [
      { label: '2025-03-15', count: 89 },
      { label: '2025-06-22', count: 87 },
      { label: '2025-09-04', count: 85 },
      { label: '2025-12-12', count: 84 },
      { label: '2025-01-20', count: 82 },
      { label: '2025-07-07', count: 80 },
    ],
  },
  {
    name: 'region',
    type: 'Category',
    present: 100,
    distinct: 4,
    summary: (
      <div className="flex flex-wrap gap-1">
        {['North', 'Central', 'South'].map((v) => (
          <span key={v} className="px-1.5 py-0.5 bg-slate-100 text-slate-700 rounded text-xs">
            {v}
          </span>
        ))}
        <span className="text-xs text-slate-500">+1</span>
      </div>
    ),
    miniData: [34, 29, 22, 15],
    distribution: [
      { label: 'North', value: 16501 },
      { label: 'Central', value: 14020 },
      { label: 'South', value: 10419 },
      { label: 'East', value: 7275 },
    ],
    topValues: [
      { label: 'North', count: 16501 },
      { label: 'Central', count: 14020 },
      { label: 'South', count: 10419 },
      { label: 'East', count: 7275 },
    ],
  },
  {
    name: 'product_category',
    type: 'Category',
    present: 99.6,
    distinct: 6,
    summary: (
      <div className="flex flex-wrap gap-1">
        {['Electronics', 'Home', 'Fashion'].map((v) => (
          <span key={v} className="px-1.5 py-0.5 bg-slate-100 text-slate-700 rounded text-xs">
            {v}
          </span>
        ))}
        <span className="text-xs text-slate-500">+3</span>
      </div>
    ),
    miniData: [32, 25, 20, 12, 7, 4],
    distribution: [
      { label: 'Electronics', value: 15188 },
      { label: 'Home', value: 11957 },
      { label: 'Fashion', value: 9788 },
      { label: 'Sports', value: 5890 },
      { label: 'Beauty', value: 3140 },
      { label: 'Other', value: 2040 },
    ],
    topValues: [
      { label: 'Electronics', count: 15188 },
      { label: 'Home', count: 11957 },
      { label: 'Fashion', count: 9788 },
      { label: 'Sports', count: 5890 },
      { label: 'Beauty', count: 3140 },
      { label: 'Other', count: 2040 },
    ],
  },
  {
    name: 'revenue_thb',
    type: 'Number',
    present: 100,
    distinct: 48215,
    summary: <span className="text-xs text-slate-700 font-tabular">฿120 – ฿89,400 · avg ฿292</span>,
    miniData: [32, 45, 58, 72, 48, 30, 18, 10, 5, 2],
    distribution: [
      { label: '฿0–5K', value: 15234 },
      { label: '฿5–15K', value: 18901 },
      { label: '฿15–30K', value: 9823 },
      { label: '฿30–50K', value: 3012 },
      { label: '฿50K+', value: 1245 },
    ],
    topValues: [
      { label: '฿0–฿5,000', count: 15234 },
      { label: '฿5,001–฿15,000', count: 18901 },
      { label: '฿15,001–฿30,000', count: 9823 },
      { label: '฿30,001–฿50,000', count: 3012 },
      { label: '฿50,001+', count: 1245 },
    ],
  },
  {
    name: 'units',
    type: 'Number',
    present: 100,
    distinct: 40,
    summary: <span className="text-xs text-slate-700 font-tabular">1 – 40 · avg 2.3</span>,
    miniData: [80, 55, 35, 20, 8, 3],
    distribution: [
      { label: '1', value: 9823 },
      { label: '2', value: 12140 },
      { label: '3', value: 8901 },
      { label: '4–10', value: 12234 },
      { label: '11–20', value: 4012 },
      { label: '21–40', value: 1105 },
    ],
    topValues: [
      { label: '1 unit', count: 9823 },
      { label: '2 units', count: 12140 },
      { label: '3 units', count: 8901 },
      { label: '4 units', count: 5234 },
      { label: '5 units', count: 3890 },
      { label: '6–10 units', count: 3110 },
    ],
  },
  {
    name: 'discount_pct',
    type: 'Number',
    present: 87.3,
    distinct: 46,
    summary: <span className="text-xs text-slate-700 font-tabular">0 – 45% · avg 8.2%</span>,
    miniData: [60, 75, 45, 25, 10],
    distribution: [
      { label: '0%', value: 14234 },
      { label: '1–10%', value: 18901 },
      { label: '11–20%', value: 7823 },
      { label: '21–30%', value: 896 },
      { label: '31–45%', value: 240 },
    ],
    topValues: [
      { label: '0%', count: 14234 },
      { label: '5%', count: 5890 },
      { label: '10%', count: 7012 },
      { label: '15%', count: 4823 },
      { label: '20%', count: 3001 },
      { label: 'Missing', count: 6121 },
    ],
  },
  {
    name: 'customer_id',
    type: 'Text',
    present: 100,
    distinct: 18904,
    summary: <span className="text-xs text-slate-700">18,904 unique values</span>,
    miniData: [3, 2, 2, 2, 1, 1, 1, 1, 1, 1],
    distribution: [
      { label: '1 order', value: 15120 },
      { label: '2 orders', value: 2890 },
      { label: '3 orders', value: 680 },
      { label: '4 orders', value: 160 },
      { label: '5+', value: 54 },
    ],
    topValues: [
      { label: 'C_00123', count: 12 },
      { label: 'C_00891', count: 11 },
      { label: 'C_01823', count: 10 },
      { label: 'C_02341', count: 10 },
      { label: 'C_00412', count: 9 },
      { label: 'C_03012', count: 9 },
    ],
  },
];

const PREVIEW_ROWS = [
  {
    order_date: '2025-01-03',
    region: 'North',
    product_category: 'Electronics',
    revenue_thb: '1,240',
    units: '2',
    discount_pct: '10',
    customer_id: 'C_00412',
  },
  {
    order_date: '2025-01-03',
    region: 'Central',
    product_category: 'Home',
    revenue_thb: '580',
    units: '1',
    discount_pct: '0',
    customer_id: 'C_01823',
  },
  {
    order_date: '2025-01-04',
    region: 'North',
    product_category: 'Electronics',
    revenue_thb: '3,890',
    units: '4',
    discount_pct: '15',
    customer_id: 'C_00891',
  },
  {
    order_date: '2025-01-05',
    region: 'South',
    product_category: 'Fashion',
    revenue_thb: '340',
    units: '1',
    discount_pct: '—',
    customer_id: 'C_02341',
  },
  {
    order_date: '2025-01-06',
    region: 'East',
    product_category: 'Home',
    revenue_thb: '720',
    units: '2',
    discount_pct: '5',
    customer_id: 'C_00123',
  },
];
const PREVIEW_COLS = [
  'order_date',
  'region',
  'product_category',
  'revenue_thb',
  'units',
  'discount_pct',
  'customer_id',
] as const;

function MiniBar({ data }: { data: number[] }) {
  const max = Math.max(...data);
  return (
    <div className="flex items-end gap-px h-5">
      {data.map((v, i) => (
        <div
          key={i}
          className="w-1.5 bg-brand-200 rounded-sm"
          style={{ height: `${(v / max) * 100}%` }}
        />
      ))}
    </div>
  );
}

function CompletenessBar({ pct }: { pct: number }) {
  const warn = pct < 90;
  return (
    <div className="flex items-center gap-2">
      <div className="w-20 h-1.5 bg-slate-200 rounded-full overflow-hidden">
        <div
          className={`h-full rounded-full ${warn ? 'bg-amber-500' : 'bg-emerald-500'}`}
          style={{ width: `${pct}%` }}
        />
      </div>
      <span className={`text-xs font-tabular ${warn ? 'text-amber-700' : 'text-slate-700'}`}>
        {pct.toFixed(1)}%
      </span>
      {warn && <AlertTriangle size={12} className="text-amber-500" />}
    </div>
  );
}

export function ProfileScreen({ analysisName, onNext, onBack }: Props) {
  const [expanded, setExpanded] = useState<string | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const known1Ref = useRef<HTMLDivElement>(null);
  const known2Ref = useRef<HTMLDivElement>(null);

  const scrollTo = (ref: React.RefObject<HTMLDivElement | null>) => {
    ref.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  return (
    <div className="flex flex-col">
      <div className="px-6 sm:px-8">
        {/* Amber banner */}
        <div className="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-6 flex items-start gap-3">
          <AlertTriangle size={16} className="text-amber-600 mt-0.5 shrink-0" />
          <div>
            <p className="text-sm font-semibold text-amber-900">
              Good to go — 2 things worth knowing
            </p>
            <div className="flex gap-3 mt-1 text-xs text-amber-800">
              <button
                onClick={() => scrollTo(known1Ref)}
                className="underline hover:text-amber-900 focus:outline-none"
              >
                Discount has 12.7% missing values
              </button>
              <button
                onClick={() => scrollTo(known2Ref)}
                className="underline hover:text-amber-900 focus:outline-none"
              >
                37 duplicate rows found
              </button>
            </div>
          </div>
        </div>

        {/* Title */}
        <div className="mb-6">
          <h1 className="text-2xl font-semibold text-slate-900">{analysisName}</h1>
          <p className="mt-1 text-sm text-slate-600">
            Review what Data Speak found in your file before continuing.
          </p>
        </div>

        {/* Stat cards */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
          <StatCard label="Rows" value="48,215" />
          <StatCard label="Columns" value="7" />
          <StatCard label="Duplicate rows" value="37" sub="Included in analysis" />
          <StatCard label="Completeness" value="98.6%" sub="Of all cells have values" />
        </div>

        {/* Things worth knowing */}
        <div className="mb-8 space-y-3">
          <h2 className="text-sm font-semibold text-slate-700 uppercase tracking-wide">
            Things worth knowing
          </h2>
          <div
            ref={known1Ref}
            className="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-xl"
          >
            <AlertTriangle size={16} className="text-amber-600 mt-0.5 shrink-0" />
            <div>
              <p className="text-sm font-medium text-amber-900">
                Discount is missing in 12.7% of rows.
              </p>
              <p className="text-xs text-amber-800 mt-0.5">
                Averages will use only the rows that have a value — 42,094 of 48,215 rows.
              </p>
            </div>
          </div>
          <div
            ref={known2Ref}
            className="flex items-start gap-3 p-4 bg-slate-50 border border-slate-200 rounded-xl"
          >
            <Info size={16} className="text-slate-500 mt-0.5 shrink-0" />
            <div>
              <p className="text-sm font-medium text-slate-800">37 rows are exact duplicates.</p>
              <p className="text-xs text-slate-600 mt-0.5">
                They're included in the analysis. If you want them removed, let us know in the
                description field on the next step.
              </p>
            </div>
          </div>
        </div>

        {/* Column table */}
        <div className="bg-white border border-slate-200 rounded-2xl overflow-hidden mb-6">
          <div className="px-5 py-4 border-b border-slate-100 flex items-center gap-2">
            <BarChart2 size={15} className="text-slate-500" />
            <h2 className="text-sm font-semibold text-slate-800">Column profiles</h2>
            <span className="ml-auto text-xs text-slate-500">
              Click any row to see the full distribution
            </span>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50">
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-600 uppercase tracking-wide w-40">
                    Column
                  </th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-600 uppercase tracking-wide">
                    Type
                  </th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-600 uppercase tracking-wide w-44">
                    Completeness
                  </th>
                  <th className="text-right px-5 py-3 text-xs font-medium text-slate-600 uppercase tracking-wide">
                    Distinct
                  </th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-600 uppercase tracking-wide">
                    Summary
                  </th>
                  <th className="w-8 px-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {COLS.map((col) => {
                  const Icon = TypeIcon[col.type];
                  const isExpanded = expanded === col.name;
                  return (
                    <>
                      <tr
                        key={col.name}
                        onClick={() => setExpanded(isExpanded ? null : col.name)}
                        className="hover:bg-slate-50 transition-colors cursor-pointer group"
                      >
                        <td className="px-5 py-3.5">
                          <code className="font-mono text-xs text-slate-700 bg-slate-100 px-2 py-0.5 rounded">
                            {col.name}
                          </code>
                        </td>
                        <td className="px-5 py-3.5">
                          <span
                            className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium border ${TypeColor[col.type]}`}
                          >
                            <Icon size={10} />
                            {col.type}
                          </span>
                        </td>
                        <td className="px-5 py-3.5">
                          <CompletenessBar pct={col.present} />
                        </td>
                        <td className="px-5 py-3.5 text-right text-slate-700 font-tabular">
                          {col.distinct.toLocaleString()}
                        </td>
                        <td className="px-5 py-3.5">
                          <div className="flex items-center gap-3">
                            {col.summary}
                            {col.type === 'Number' && <MiniBar data={col.miniData} />}
                          </div>
                        </td>
                        <td className="px-3 py-3.5 text-slate-400">
                          {isExpanded ? <ChevronUp size={14} /> : <ChevronRight size={14} />}
                        </td>
                      </tr>

                      {isExpanded && (
                        <tr key={`${col.name}-detail`} className="bg-slate-50">
                          <td colSpan={6} className="px-5 py-5">
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                              <div>
                                <p className="text-xs font-semibold text-slate-600 uppercase tracking-wide mb-3">
                                  Distribution
                                </p>
                                <ResponsiveContainer width="100%" height={140}>
                                  <BarChart
                                    data={col.distribution}
                                    margin={{ top: 4, right: 4, left: 0, bottom: 0 }}
                                  >
                                    <XAxis
                                      dataKey="label"
                                      tick={{ fontSize: 10, fill: '#475569' }}
                                      axisLine={false}
                                      tickLine={false}
                                    />
                                    <YAxis
                                      tick={{ fontSize: 10, fill: '#475569' }}
                                      axisLine={false}
                                      tickLine={false}
                                      width={40}
                                    />
                                    <Tooltip
                                      contentStyle={{
                                        fontSize: 11,
                                        border: '1px solid #E2E8F0',
                                        borderRadius: 8,
                                        boxShadow: 'none',
                                      }}
                                    />
                                    <Bar dataKey="value" fill="#087A43" radius={[3, 3, 0, 0]} />
                                  </BarChart>
                                </ResponsiveContainer>
                              </div>
                              <div>
                                <p className="text-xs font-semibold text-slate-600 uppercase tracking-wide mb-3">
                                  Top values
                                </p>
                                <div className="space-y-1.5">
                                  {col.topValues.slice(0, 8).map((v) => {
                                    const total = col.topValues.reduce((s, t) => s + t.count, 0);
                                    const pct = ((v.count / total) * 100).toFixed(1);
                                    return (
                                      <div key={v.label} className="flex items-center gap-2">
                                        <span className="text-xs text-slate-700 w-28 truncate font-mono">
                                          {v.label}
                                        </span>
                                        <div className="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                                          <div
                                            className="h-full bg-brand-400 rounded-full"
                                            style={{ width: `${pct}%` }}
                                          />
                                        </div>
                                        <span className="text-xs text-slate-600 font-tabular w-16 text-right">
                                          {v.count.toLocaleString()}
                                        </span>
                                      </div>
                                    );
                                  })}
                                </div>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                    </>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>

        {/* Preview first 5 rows */}
        <div className="bg-white border border-slate-200 rounded-2xl overflow-hidden mb-8">
          <button
            onClick={() => setPreviewOpen((v) => !v)}
            className="w-full flex items-center justify-between px-5 py-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors focus:outline-none focus:ring-inset focus:ring-2 focus:ring-brand-700"
          >
            <span>Preview first 5 rows</span>
            {previewOpen ? <ChevronUp size={15} /> : <ChevronDown size={15} />}
          </button>
          {previewOpen && (
            <div className="border-t border-slate-100 overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-100">
                    {PREVIEW_COLS.map((c) => (
                      <th key={c} className="px-4 py-2.5 text-left font-medium text-slate-600">
                        <code className="font-mono">{c}</code>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {PREVIEW_ROWS.map((row, i) => (
                    <tr key={i} className="hover:bg-slate-50">
                      {PREVIEW_COLS.map((c) => (
                        <td
                          key={c}
                          className={`px-4 py-2.5 text-slate-700 ${row[c] === '—' ? 'text-slate-400' : ''}`}
                        >
                          {row[c]}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* Sticky footer */}
      <div className="sticky bottom-0 bg-white/95 backdrop-blur border-t border-slate-200 px-6 sm:px-8 py-4 flex gap-3">
        <button
          onClick={onBack}
          className="px-5 py-2.5 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
        >
          Back
        </button>
        <button
          onClick={onNext}
          className="px-5 py-2.5 bg-brand-700 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
        >
          Continue to define fields
        </button>
      </div>
    </div>
  );
}
