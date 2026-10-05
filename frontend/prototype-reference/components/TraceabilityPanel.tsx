import { X, CheckCircle2, XCircle, ShieldCheck } from 'lucide-react';
import type { Claim } from '../types';
import { evidenceMap } from '../services/api';

interface Props {
  claim: Claim;
  onClose: () => void;
}

export function TraceabilityPanel({ claim, onClose }: Props) {
  const evidence = evidenceMap[claim.evidenceId];
  const verified = claim.status === 'supported';

  return (
    <div className="flex flex-col h-full">
      <div className="flex items-start justify-between p-5 border-b border-slate-200">
        <div>
          <p className="text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">
            Claim traceability
          </p>
          <div
            className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${verified ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}
          >
            {verified ? <CheckCircle2 size={12} /> : <XCircle size={12} />}
            {verified ? 'Verified' : 'Rejected'}
          </div>
        </div>
        <button
          onClick={onClose}
          className="p-1.5 hover:bg-slate-100 rounded-lg transition-colors text-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-700"
          aria-label="Close panel"
        >
          <X size={18} />
        </button>
      </div>

      <div className="flex-1 overflow-y-auto p-5 space-y-5">
        <div>
          <p className="text-xs font-medium text-slate-500 mb-1.5">Claim</p>
          <p className="text-sm text-slate-800 leading-relaxed bg-slate-50 rounded-lg p-3 border border-slate-200">
            "{claim.sentence}"
          </p>
        </div>

        {claim.rejectionReason && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-3">
            <p className="text-xs font-semibold text-red-700 mb-0.5 flex items-center gap-1.5">
              <XCircle size={12} /> Rejection reason
            </p>
            <p className="text-sm text-red-700">{claim.rejectionReason}</p>
          </div>
        )}

        {evidence && (
          <>
            <div className="grid grid-cols-2 gap-3 text-sm">
              <div>
                <p className="text-xs text-slate-500 mb-0.5">Evidence ID</p>
                <p className="font-mono text-slate-700 text-xs">{evidence.id}</p>
              </div>
              <div>
                <p className="text-xs text-slate-500 mb-0.5">Analysis type</p>
                <p className="text-slate-700">{evidence.type}</p>
              </div>
              <div className="col-span-2">
                <p className="text-xs text-slate-500 mb-0.5">Method</p>
                <p className="text-slate-700">{evidence.method}</p>
              </div>
            </div>

            <div>
              <p className="text-xs font-medium text-slate-500 mb-2">Source columns</p>
              <div className="space-y-1.5">
                {evidence.sourceColumns.map((col) => (
                  <div key={col.originalName} className="flex items-center gap-2 text-sm">
                    <span className="text-slate-800">{col.businessName}</span>
                    <span className="text-slate-400">·</span>
                    <code className="font-mono text-xs text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded">
                      {col.originalName}
                    </code>
                  </div>
                ))}
              </div>
            </div>

            <div>
              <p className="text-xs font-medium text-slate-500 mb-2">Calculated values</p>
              <table className="w-full text-sm">
                <tbody>
                  {evidence.calculatedValues.map((row) => (
                    <tr key={row.label} className="border-b border-slate-100 last:border-0">
                      <td className="py-1.5 text-slate-600 pr-4">{row.label}</td>
                      <td className="py-1.5 text-slate-900 font-medium font-tabular text-right">
                        {row.value}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {claim.claimedValue && claim.calculatedValue && (
              <div>
                <p className="text-xs font-medium text-slate-500 mb-2">Value comparison</p>
                <div className="grid grid-cols-2 gap-3">
                  <div
                    className={`rounded-lg p-3 border ${verified ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200'}`}
                  >
                    <p className="text-xs text-slate-500 mb-1">Claimed value</p>
                    <p
                      className={`text-sm font-semibold font-tabular ${verified ? 'text-emerald-800' : 'text-red-800'}`}
                    >
                      {claim.claimedValue}
                    </p>
                  </div>
                  <div className="rounded-lg p-3 bg-slate-50 border border-slate-200">
                    <p className="text-xs text-slate-500 mb-1">Calculated value</p>
                    <p className="text-sm font-semibold text-slate-800 font-tabular">
                      {claim.calculatedValue}
                    </p>
                  </div>
                </div>
              </div>
            )}
          </>
        )}

        <div className="flex items-center gap-2 text-xs text-slate-400 pt-2 border-t border-slate-100">
          <ShieldCheck size={12} />
          Numbers calculated before narrative was written
        </div>
      </div>
    </div>
  );
}
