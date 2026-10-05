import { useEffect, useState } from 'react';
import { Plus, Play, RefreshCw, FileText, Loader2 } from 'lucide-react';
import { listAnalyses } from '../services/api';
import type { Analysis, AnalysisStatus } from '../types';

interface Props {
  onNew: () => void;
  onOpen: (id: string) => void;
  onRunAnalysis: (id: string) => void;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function formatDate(dateStr: string) {
  const [y, m, d] = dateStr.split('-').map(Number);
  return `${d} ${MONTHS[m - 1]} ${y}`;
}

type UIStatus = 'ready' | 'analyzing' | 'complete' | 'failed';

function uiStatus(s: AnalysisStatus): UIStatus {
  if (s === 'completed') return 'complete';
  if (s === 'failed') return 'failed';
  if (s === 'analyzing' || s === 'analyzed' || s === 'generating') return 'analyzing';
  return 'ready';
}

const statusConfig: Record<UIStatus, { label: string; className: string }> = {
  ready: { label: 'Ready to analyze', className: 'bg-blue-50 text-blue-700 border-blue-200' },
  analyzing: { label: 'Analyzing…', className: 'bg-amber-50 text-amber-700 border-amber-200' },
  complete: {
    label: 'Report ready',
    className: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  },
  failed: { label: 'Needs attention', className: 'bg-red-50 text-red-700 border-red-200' },
};

export function DashboardScreen({ onNew, onOpen, onRunAnalysis }: Props) {
  const [analyses, setAnalyses] = useState<Analysis[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    listAnalyses().then((data) => {
      setAnalyses(data);
      setLoading(false);
    });
  }, []);

  const handleDelete = (e: React.MouseEvent, id: string) => {
    e.stopPropagation();
    setAnalyses((prev) => prev.filter((a) => a.id !== id));
  };

  return (
    <div>
      <div className="flex items-center justify-between mb-8">
        <h1 className="text-2xl font-semibold text-slate-900">Your analyses</h1>
        <button
          onClick={onNew}
          className="flex items-center gap-2 px-4 py-2.5 bg-brand-700 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
        >
          <Plus size={16} />
          New analysis
        </button>
      </div>

      {loading ? (
        <div className="space-y-3">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="h-14 bg-slate-100 rounded-xl animate-pulse" />
          ))}
        </div>
      ) : analyses.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-24 text-center">
          <p className="text-slate-600 mb-4">No analyses yet. Upload a CSV to get started.</p>
          <button
            onClick={onNew}
            className="px-5 py-2.5 bg-brand-700 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition-colors"
          >
            Start your first analysis
          </button>
        </div>
      ) : (
        <div className="bg-white border border-slate-200 rounded-2xl overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50">
                <th className="text-left px-5 py-3.5 text-xs font-medium text-slate-600 uppercase tracking-wide">
                  Title
                </th>
                <th className="text-left px-5 py-3.5 text-xs font-medium text-slate-600 uppercase tracking-wide hidden sm:table-cell">
                  File
                </th>
                <th className="text-right px-5 py-3.5 text-xs font-medium text-slate-600 uppercase tracking-wide hidden md:table-cell">
                  Rows
                </th>
                <th className="text-left px-5 py-3.5 text-xs font-medium text-slate-600 uppercase tracking-wide hidden lg:table-cell">
                  Created
                </th>
                <th className="text-left px-5 py-3.5 text-xs font-medium text-slate-600 uppercase tracking-wide">
                  Status
                </th>
                <th className="px-5 py-3.5 text-left text-xs font-medium text-slate-600 uppercase tracking-wide">
                  Action
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {analyses.map((a) => {
                const ui = uiStatus(a.status);
                const cfg = statusConfig[ui];
                return (
                  <tr
                    key={a.id}
                    onClick={() => (ui === 'complete' ? onOpen(a.id) : undefined)}
                    className={`transition-colors group ${ui === 'complete' ? 'cursor-pointer hover:bg-slate-50' : 'hover:bg-slate-50/60'}`}
                  >
                    <td className="px-5 py-4">
                      <span className="font-medium text-slate-900">{a.title}</span>
                    </td>
                    <td className="px-5 py-4 text-slate-600 hidden sm:table-cell font-mono text-xs">
                      {a.fileName}
                    </td>
                    <td className="px-5 py-4 text-slate-700 text-right font-tabular hidden md:table-cell">
                      {a.rows.toLocaleString()}
                    </td>
                    <td className="px-5 py-4 text-slate-600 hidden lg:table-cell">
                      {formatDate(a.createdAt)}
                    </td>
                    <td className="px-5 py-4">
                      <span
                        className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-medium border ${cfg.className}`}
                      >
                        {ui === 'analyzing' && <Loader2 size={10} className="animate-spin" />}
                        {cfg.label}
                      </span>
                    </td>
                    <td className="px-5 py-4" onClick={(e) => e.stopPropagation()}>
                      {ui === 'complete' && (
                        <button
                          onClick={() => onOpen(a.id)}
                          className="flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-700 rounded px-2 py-1 hover:bg-brand-50 transition-colors"
                        >
                          <FileText size={13} />
                          View report
                        </button>
                      )}
                      {ui === 'ready' && (
                        <button
                          onClick={() => onRunAnalysis(a.id)}
                          className="flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-700 rounded px-2 py-1 hover:bg-brand-50 transition-colors"
                        >
                          <Play size={13} />
                          Run analysis
                        </button>
                      )}
                      {ui === 'failed' && (
                        <div className="flex flex-col gap-1">
                          <button
                            onClick={() => onRunAnalysis(a.id)}
                            className="flex items-center gap-1.5 text-xs font-medium text-red-700 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 rounded px-2 py-1 hover:bg-red-50 transition-colors"
                          >
                            <RefreshCw size={13} />
                            Retry
                          </button>
                          {a.failedReason && (
                            <p className="text-xs text-slate-600 max-w-[200px] leading-snug px-2">
                              {a.failedReason}
                            </p>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
