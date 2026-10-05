import { useState, useRef, useEffect } from 'react';
import {
  BarChart2,
  Layers,
  Clock,
  IdCard,
  EyeOff,
  ChevronDown,
  ChevronUp,
  AlertTriangle,
  CheckCircle2,
  Info,
  HelpCircle,
  X,
} from 'lucide-react';

interface Props {
  analysisId: string;
  analysisName: string;
  onBack: () => void;
  onNext: () => void;
}

type Role = 'Measure' | 'Dimension' | 'Time' | 'Identifier' | 'Ignore';

interface ColumnDef {
  name: string;
  suggestedRole: Role;
  role: Role;
  description: string;
  unit: string;
  aggregation: string;
  touched: boolean;
  descOpen: boolean;
}

const ROLE_CONFIG: Record<
  Role,
  { label: string; icon: React.ElementType; color: string; desc: string }
> = {
  Measure: {
    label: 'Measure',
    icon: BarChart2,
    color: 'text-brand-700 bg-brand-50 border-brand-300',
    desc: 'A number you want to track or compare',
  },
  Dimension: {
    label: 'Dimension',
    icon: Layers,
    color: 'text-violet-700 bg-violet-50 border-violet-300',
    desc: 'A category to group or filter by',
  },
  Time: {
    label: 'Time',
    icon: Clock,
    color: 'text-amber-700 bg-amber-50 border-amber-300',
    desc: 'A date or time period for trends',
  },
  Identifier: {
    label: 'Identifier',
    icon: IdCard,
    color: 'text-slate-700 bg-slate-50 border-slate-300',
    desc: 'A unique key, not for grouping',
  },
  Ignore: {
    label: 'Ignore',
    icon: EyeOff,
    color: 'text-slate-400 bg-white border-slate-200',
    desc: 'Skip this column entirely',
  },
};

const ALL_ROLES: Role[] = ['Measure', 'Dimension', 'Time', 'Identifier', 'Ignore'];

const INITIAL_COLS: ColumnDef[] = [
  {
    name: 'order_date',
    suggestedRole: 'Time',
    role: 'Time',
    description: '',
    unit: '',
    aggregation: '',
    touched: false,
    descOpen: false,
  },
  {
    name: 'region',
    suggestedRole: 'Dimension',
    role: 'Dimension',
    description: '',
    unit: '',
    aggregation: '',
    touched: false,
    descOpen: false,
  },
  {
    name: 'product_category',
    suggestedRole: 'Dimension',
    role: 'Dimension',
    description: '',
    unit: '',
    aggregation: '',
    touched: false,
    descOpen: false,
  },
  {
    name: 'revenue_thb',
    suggestedRole: 'Measure',
    role: 'Measure',
    description: '',
    unit: '฿',
    aggregation: 'Sum',
    touched: false,
    descOpen: false,
  },
  {
    name: 'units',
    suggestedRole: 'Measure',
    role: 'Measure',
    description: '',
    unit: 'qty',
    aggregation: 'Sum',
    touched: false,
    descOpen: false,
  },
  {
    name: 'discount_pct',
    suggestedRole: 'Measure',
    role: 'Measure',
    description: '',
    unit: '%',
    aggregation: 'Avg',
    touched: false,
    descOpen: false,
  },
  {
    name: 'customer_id',
    suggestedRole: 'Identifier',
    role: 'Identifier',
    description: '',
    unit: '',
    aggregation: '',
    touched: false,
    descOpen: false,
  },
];

const AGGS = ['Sum', 'Avg', 'Min', 'Max', 'Count'];

function PopoverHelp({ open, onClose }: { open: boolean; onClose: () => void }) {
  if (!open) return null;
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/30"
      onClick={onClose}
    >
      <div
        className="bg-white rounded-2xl shadow-2xl p-6 max-w-sm w-full"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-sm font-semibold text-slate-800">What do these roles mean?</h3>
          <button onClick={onClose} className="p-1 hover:bg-slate-100 rounded-lg transition-colors">
            <X size={14} />
          </button>
        </div>
        <div className="space-y-3">
          {ALL_ROLES.map((role) => {
            const { icon: Icon, label, color, desc } = ROLE_CONFIG[role];
            return (
              <div key={role} className="flex items-start gap-2.5">
                <span
                  className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium border shrink-0 mt-0.5 ${color}`}
                >
                  <Icon size={10} />
                  {label}
                </span>
                <p className="text-xs text-slate-700">{desc}</p>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

function buildPreviewSentences(cols: ColumnDef[]): string[] {
  const measures = cols.filter((c) => c.role === 'Measure');
  const dims = cols.filter((c) => c.role === 'Dimension');
  const time = cols.find((c) => c.role === 'Time');
  const sentences: string[] = [];

  if (measures.length === 0)
    return ['No measures defined yet — add at least one measure to continue.'];

  const measureList = measures.map((m) => {
    const label = m.name.replace(/_/g, ' ');
    const unit = m.unit ? ` (${m.unit})` : '';
    return `${label}${unit}`;
  });

  if (dims.length > 0 && time) {
    sentences.push(
      `Data Speak will analyse ${measureList.join(' and ')} over time (${time.name.replace(/_/g, ' ')}), broken down by ${dims.map((d) => d.name.replace(/_/g, ' ')).join(' and ')}.`,
    );
  } else if (dims.length > 0) {
    sentences.push(
      `Data Speak will analyse ${measureList.join(' and ')} broken down by ${dims.map((d) => d.name.replace(/_/g, ' ')).join(' and ')}.`,
    );
  } else if (time) {
    sentences.push(
      `Data Speak will track ${measureList.join(' and ')} over time (${time.name.replace(/_/g, ' ')}).`,
    );
  } else {
    sentences.push(`Data Speak will summarise ${measureList.join(' and ')}.`);
  }

  if (cols.some((c) => c.role === 'Ignore')) {
    const ignored = cols
      .filter((c) => c.role === 'Ignore')
      .map((c) => c.name)
      .join(', ');
    sentences.push(`Ignored columns: ${ignored}.`);
  }

  return sentences;
}

export function DefineScreen({ analysisName, onBack, onNext }: Props) {
  const [cols, setCols] = useState<ColumnDef[]>(INITIAL_COLS);
  const [savedAt, setSavedAt] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [helpOpen, setHelpOpen] = useState(false);
  const autoSaveRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const reviewed = cols.filter((c) => c.touched).length;

  const setRole = (idx: number, role: Role) => {
    setCols((prev) =>
      prev.map((c, i) =>
        i === idx
          ? {
              ...c,
              role,
              touched: true,
              unit: role === 'Measure' ? c.unit : '',
              aggregation: role === 'Measure' ? c.aggregation || 'Sum' : '',
            }
          : c,
      ),
    );
    scheduleAutosave();
  };

  const scheduleAutosave = () => {
    if (autoSaveRef.current) clearTimeout(autoSaveRef.current);
    autoSaveRef.current = setTimeout(() => {
      const now = new Date();
      setSavedAt(`${now.getHours()}:${String(now.getMinutes()).padStart(2, '0')}`);
    }, 800);
  };

  useEffect(
    () => () => {
      if (autoSaveRef.current) clearTimeout(autoSaveRef.current);
    },
    [],
  );

  const validate = () => {
    const errs: Record<string, string> = {};
    const measures = cols.filter((c) => c.role === 'Measure');
    if (measures.length === 0)
      errs['__global'] = 'At least one column must be set to Measure before continuing.';
    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleContinue = () => {
    if (validate()) onNext();
  };

  const hasMeasure = cols.some((c) => c.role === 'Measure');
  const hasTime = cols.some((c) => c.role === 'Time');
  const preview = buildPreviewSentences(cols);

  return (
    <>
      <PopoverHelp open={helpOpen} onClose={() => setHelpOpen(false)} />

      <div className="flex flex-col">
        <div className="px-6 sm:px-8">
          <div className="mb-6">
            <div className="flex items-baseline justify-between flex-wrap gap-2">
              <div>
                <h1 className="text-2xl font-semibold text-slate-900">{analysisName}</h1>
                <p className="mt-1 text-sm text-slate-600">
                  Tell Data Speak what each column means.
                </p>
              </div>
              <button
                onClick={() => setHelpOpen(true)}
                className="flex items-center gap-1.5 text-xs text-slate-600 hover:text-brand-700 transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 rounded"
              >
                <HelpCircle size={13} />
                What do these roles mean?
              </button>
            </div>
            <div className="flex items-center gap-3 mt-3">
              <span className="text-xs font-medium text-slate-700">
                {reviewed} of {cols.length} reviewed
              </span>
              <div className="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                <div
                  className="h-full bg-brand-700 rounded-full transition-all duration-300"
                  style={{ width: `${(reviewed / cols.length) * 100}%` }}
                />
              </div>
              {savedAt && (
                <span className="text-xs text-slate-500 flex items-center gap-1">
                  <CheckCircle2 size={11} className="text-emerald-500" />
                  Saved {savedAt}
                </span>
              )}
            </div>
          </div>

          {errors['__global'] && (
            <div className="mb-5 flex items-start gap-2 bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700">
              <AlertTriangle size={15} className="mt-0.5 shrink-0" />
              {errors['__global']}
            </div>
          )}

          <div className="flex gap-6">
            {/* Left: column definitions */}
            <div className="flex-1 min-w-0 space-y-3">
              {cols.map((col, idx) => {
                const currentCfg = ROLE_CONFIG[col.role];
                const Icon = currentCfg.icon;
                const isSuggested = !col.touched;

                return (
                  <div
                    key={col.name}
                    className={`bg-white border rounded-2xl transition-colors ${errors[col.name] ? 'border-red-300' : 'border-slate-200'}`}
                  >
                    <div className="px-5 py-4">
                      <div className="flex items-start gap-3 flex-wrap sm:flex-nowrap">
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-2 mb-3">
                            <code className="font-mono text-sm text-slate-700 bg-slate-100 px-2 py-0.5 rounded">
                              {col.name}
                            </code>
                            {isSuggested && (
                              <span className="text-xs font-medium text-brand-700 bg-brand-50 border border-brand-200 px-1.5 py-0.5 rounded">
                                Suggested
                              </span>
                            )}
                          </div>

                          {/* Role segmented control */}
                          <div className="flex flex-wrap gap-1.5 mb-3">
                            {ALL_ROLES.map((role) => {
                              const cfg = ROLE_CONFIG[role];
                              const RIcon = cfg.icon;
                              const active = col.role === role;
                              return (
                                <button
                                  key={role}
                                  onClick={() => setRole(idx, role)}
                                  className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium border transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700
                                    ${active ? cfg.color : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'}`}
                                  aria-pressed={active}
                                  title={cfg.desc}
                                >
                                  <RIcon size={11} />
                                  {role}
                                </button>
                              );
                            })}
                          </div>

                          {/* Measure-only: unit + aggregation */}
                          {col.role === 'Measure' && (
                            <div className="flex gap-3 mb-2">
                              <div>
                                <label className="block text-xs text-slate-600 mb-1">Unit</label>
                                <input
                                  type="text"
                                  value={col.unit}
                                  onChange={(e) => {
                                    setCols((prev) =>
                                      prev.map((c, i) =>
                                        i === idx ? { ...c, unit: e.target.value } : c,
                                      ),
                                    );
                                    scheduleAutosave();
                                  }}
                                  placeholder="e.g. ฿, %, qty"
                                  className="w-24 px-2.5 py-1.5 text-xs rounded-lg border border-slate-300 hover:border-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-700"
                                />
                              </div>
                              <div>
                                <label className="block text-xs text-slate-600 mb-1">
                                  Aggregation
                                </label>
                                <select
                                  value={col.aggregation}
                                  onChange={(e) => {
                                    setCols((prev) =>
                                      prev.map((c, i) =>
                                        i === idx ? { ...c, aggregation: e.target.value } : c,
                                      ),
                                    );
                                    scheduleAutosave();
                                  }}
                                  className="px-2.5 py-1.5 text-xs rounded-lg border border-slate-300 hover:border-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-700 bg-white"
                                >
                                  {AGGS.map((a) => (
                                    <option key={a}>{a}</option>
                                  ))}
                                </select>
                              </div>
                            </div>
                          )}

                          {/* Expand description */}
                          <button
                            onClick={() =>
                              setCols((prev) =>
                                prev.map((c, i) =>
                                  i === idx ? { ...c, descOpen: !c.descOpen } : c,
                                ),
                              )
                            }
                            className="text-xs text-brand-700 hover:underline focus:outline-none flex items-center gap-1"
                          >
                            {col.descOpen ? <ChevronUp size={11} /> : <ChevronDown size={11} />}
                            {col.description ? 'Edit description' : '+ Add description'}
                          </button>

                          {col.descOpen && (
                            <textarea
                              rows={2}
                              value={col.description}
                              onChange={(e) => {
                                setCols((prev) =>
                                  prev.map((c, i) =>
                                    i === idx ? { ...c, description: e.target.value } : c,
                                  ),
                                );
                                scheduleAutosave();
                              }}
                              placeholder="Optional: describe what this column tracks…"
                              className="mt-2 w-full px-3 py-2 text-xs rounded-lg border border-slate-300 hover:border-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-700 resize-none"
                            />
                          )}
                        </div>

                        {/* Role badge summary */}
                        <div
                          className={`shrink-0 flex items-center gap-1 px-2.5 py-1.5 rounded-xl border text-xs font-medium ${currentCfg.color}`}
                        >
                          <Icon size={12} />
                          {col.role}
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>

            {/* Right: live preview panel (desktop) */}
            <div className="hidden lg:block w-64 xl:w-72 shrink-0">
              <div className="sticky top-4 bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div className="px-4 py-3 border-b border-slate-100 bg-slate-50">
                  <p className="text-xs font-semibold text-slate-700 uppercase tracking-wide">
                    What Data Speak will analyse
                  </p>
                </div>
                <div className="px-4 py-4 space-y-3">
                  {!hasTime && hasMeasure && (
                    <div className="flex items-start gap-2 p-2.5 bg-amber-50 border border-amber-200 rounded-xl">
                      <AlertTriangle size={13} className="text-amber-600 mt-0.5 shrink-0" />
                      <p className="text-xs text-amber-800">
                        No Time column — trend charts won't be available.
                      </p>
                    </div>
                  )}
                  {preview.map((s, i) => (
                    <p
                      key={i}
                      className={`text-xs leading-relaxed ${hasMeasure ? 'text-slate-700' : 'text-slate-400 italic'}`}
                    >
                      {s}
                    </p>
                  ))}

                  <div className="pt-2 border-t border-slate-100 space-y-1.5">
                    {['Measure', 'Dimension', 'Time', 'Identifier', 'Ignore'].map((role) => {
                      const count = cols.filter((c) => c.role === role).length;
                      if (count === 0) return null;
                      const cfg = ROLE_CONFIG[role as Role];
                      const RIcon = cfg.icon;
                      return (
                        <div
                          key={role}
                          className="flex items-center gap-1.5 text-xs text-slate-600"
                        >
                          <RIcon size={11} className="text-slate-500" />
                          <span>
                            {count} {role}
                            {count > 1 ? (role === 'Ignore' ? 'd' : 's') : ''}
                          </span>
                        </div>
                      );
                    })}
                  </div>
                </div>

                {!hasMeasure && (
                  <div className="px-4 py-3 border-t border-slate-100 bg-red-50">
                    <div className="flex items-start gap-2">
                      <Info size={13} className="text-red-600 mt-0.5 shrink-0" />
                      <p className="text-xs text-red-700">Add at least one Measure to continue.</p>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* Mobile preview panel */}
          <div className="lg:hidden mt-5 bg-slate-50 border border-slate-200 rounded-2xl p-4">
            <p className="text-xs font-semibold text-slate-700 uppercase tracking-wide mb-2">
              What Data Speak will analyse
            </p>
            {!hasTime && hasMeasure && (
              <div className="flex items-start gap-2 mb-2 p-2.5 bg-amber-50 border border-amber-200 rounded-xl">
                <AlertTriangle size={13} className="text-amber-600 mt-0.5 shrink-0" />
                <p className="text-xs text-amber-800">
                  No Time column — trend charts won't be available.
                </p>
              </div>
            )}
            {preview.map((s, i) => (
              <p
                key={i}
                className={`text-xs leading-relaxed ${hasMeasure ? 'text-slate-700' : 'text-slate-400 italic'}`}
              >
                {s}
              </p>
            ))}
          </div>
        </div>

        {/* Sticky footer */}
        <div className="sticky bottom-0 bg-white/95 backdrop-blur border-t border-slate-200 px-6 sm:px-8 py-4 flex gap-3 mt-8">
          <button
            onClick={onBack}
            className="px-5 py-2.5 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
          >
            Back
          </button>
          <button
            onClick={handleContinue}
            disabled={!hasMeasure}
            className="px-5 py-2.5 bg-brand-700 hover:bg-brand-600 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
          >
            Run analysis
          </button>
        </div>
      </div>
    </>
  );
}
