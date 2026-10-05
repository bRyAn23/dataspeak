import { useEffect, useState, useRef, useCallback } from 'react';
import {
  ShieldCheck,
  ChevronDown,
  ChevronUp,
  CheckCircle2,
  XCircle,
  FileDown,
  Plus,
  Calendar,
  Database,
  ArrowLeft,
} from 'lucide-react';
import { getReport } from '../services/api';
import type { Report, Claim } from '../types';
import { ChartCard } from '../components/ChartCard';
import { TraceabilityPanel } from '../components/TraceabilityPanel';

interface Props {
  analysisId: string;
  onNew: () => void;
  onBack: () => void;
}

function ClaimSentence({
  claim,
  active,
  onClick,
}: {
  claim: Claim;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <>
      <span
        role="button"
        tabIndex={0}
        onClick={onClick}
        onKeyDown={(e) => e.key === 'Enter' && onClick()}
        className={`claim-link ${active ? 'active' : ''}`}
        title="Click to see how this was calculated"
      >
        {claim.sentence}
      </span>
      {claim.status === 'supported' && (
        <ShieldCheck
          size={13}
          className="inline-block ml-1 mb-0.5 text-emerald-600 align-middle"
          aria-label="Verified"
        />
      )}
    </>
  );
}

export function ReportScreen({ analysisId, onNew, onBack }: Props) {
  const [report, setReport] = useState<Report | null>(null);
  const [activeClaim, setActiveClaim] = useState<Claim | null>(null);
  const [showVerification, setShowVerification] = useState(false);
  const panelRef = useRef<HTMLDivElement>(null);
  const verificationRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    getReport(analysisId).then(setReport);
  }, [analysisId]);
  useEffect(() => {
    if (activeClaim && panelRef.current) panelRef.current.scrollTop = 0;
  }, [activeClaim]);

  const openVerification = useCallback(() => {
    setShowVerification(true);
    setTimeout(
      () => verificationRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
      50,
    );
  }, []);

  const toggleClaim = (claim: Claim) =>
    setActiveClaim((prev) => (prev?.id === claim.id ? null : claim));

  if (!report) {
    return (
      <div className="space-y-4">
        <div className="h-24 bg-slate-100 rounded-2xl animate-pulse" />
        <div className="h-40 bg-slate-100 rounded-2xl animate-pulse" />
        <div className="h-64 bg-slate-100 rounded-2xl animate-pulse" />
      </div>
    );
  }

  const allVerified = report.verifiedClaims === report.totalClaims;

  return (
    <div className="flex gap-6 relative">
      {/* Main report */}
      <div
        className={`flex-1 min-w-0 transition-all duration-300 ${activeClaim ? 'lg:max-w-[calc(100%-360px)]' : ''}`}
      >
        {/* ← All analyses breadcrumb */}
        <button
          onClick={onBack}
          className="flex items-center gap-1.5 text-sm text-slate-600 hover:text-brand-700 mb-5 transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 rounded"
        >
          <ArrowLeft size={15} />
          All analyses
        </button>

        {/* Header card */}
        <div className="bg-white border border-slate-200 rounded-2xl p-6 mb-5">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h1 className="text-xl font-semibold text-slate-900">{report.title}</h1>
              <div className="flex flex-wrap items-center gap-4 mt-2 text-sm text-slate-600">
                <span className="flex items-center gap-1.5">
                  <Database size={14} /> {report.datasetName}
                </span>
                <span className="flex items-center gap-1.5">
                  <Calendar size={14} /> Generated {report.generatedAt}
                </span>
              </div>
            </div>
            {/* Verification badge — click opens Verification details */}
            <button
              onClick={openVerification}
              className={`flex items-center gap-2 px-3.5 py-2 rounded-xl border text-sm font-medium transition-colors hover:opacity-80 focus:outline-none focus:ring-2 focus:ring-brand-700 ${allVerified ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200'}`}
              title="Click to view verification details"
            >
              <ShieldCheck size={16} />
              {report.verifiedClaims} of {report.totalClaims} claims verified
            </button>
          </div>

          {/* Hint text */}
          <p className="mt-4 text-xs text-slate-600">
            Click any underlined sentence to see how it was calculated.
          </p>

          {/* Action buttons: Export PDF = primary, New analysis = secondary */}
          <div className="flex gap-2.5 mt-4">
            <button className="flex items-center gap-2 px-4 py-2 bg-brand-700 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2">
              <FileDown size={15} />
              Export PDF
            </button>
            <button
              onClick={onNew}
              className="flex items-center gap-2 px-4 py-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
            >
              <Plus size={15} />
              New analysis
            </button>
          </div>
        </div>

        {/* Executive summary — fully clickable claims */}
        <div className="bg-white border border-slate-200 rounded-2xl p-6 mb-5">
          <h2 className="text-sm font-semibold text-slate-600 uppercase tracking-wide mb-4">
            Executive summary
          </h2>
          <div className="space-y-2.5 text-slate-800 leading-relaxed text-sm">
            {report.executiveClaims.map((claim) => (
              <p key={claim.id}>
                <ClaimSentence
                  claim={claim}
                  active={activeClaim?.id === claim.id}
                  onClick={() => toggleClaim(claim)}
                />
              </p>
            ))}
          </div>
        </div>

        {/* Insights */}
        {report.insights.map((insight) => (
          <div key={insight.rank} className="bg-white border border-slate-200 rounded-2xl p-6 mb-5">
            <div className="flex items-center gap-3 mb-5">
              <div className="w-7 h-7 bg-brand-50 rounded-lg flex items-center justify-center text-sm font-semibold text-brand-700">
                {insight.rank}
              </div>
              <h2 className="text-base font-semibold text-slate-900">{insight.headline}</h2>
            </div>
            <div className="mb-5">
              <ChartCard chart={insight.chart} />
            </div>
            <div className="space-y-2 text-sm text-slate-700 leading-relaxed">
              {insight.claims.map((claim) => (
                <p key={claim.id}>
                  <ClaimSentence
                    claim={claim}
                    active={activeClaim?.id === claim.id}
                    onClick={() => toggleClaim(claim)}
                  />
                </p>
              ))}
            </div>
          </div>
        ))}

        {/* Data notes */}
        <div className="bg-amber-50 border border-amber-200 rounded-2xl p-6 mb-5">
          <h2 className="text-sm font-semibold text-amber-800 mb-3">Data notes</h2>
          <ul className="space-y-2">
            {report.dataNotes.map((note, i) => (
              <li key={i} className="flex items-start gap-2 text-sm text-amber-900">
                <span className="mt-1.5 w-1.5 h-1.5 bg-amber-500 rounded-full shrink-0" />
                {note}
              </li>
            ))}
          </ul>
        </div>

        {/* Verification details */}
        <div
          ref={verificationRef}
          className="bg-white border border-slate-200 rounded-2xl overflow-hidden mb-5"
        >
          <button
            onClick={() => setShowVerification((v) => !v)}
            className="w-full flex items-center justify-between px-6 py-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors focus:outline-none focus:ring-inset focus:ring-2 focus:ring-brand-700"
            aria-expanded={showVerification}
          >
            <span>Verification details</span>
            {showVerification ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
          </button>
          {showVerification && (
            <div className="border-t border-slate-100 divide-y divide-slate-100">
              {report.allClaims.map((claim) => (
                <div key={claim.id} className="px-6 py-4 flex items-start gap-3">
                  {claim.status === 'supported' ? (
                    <CheckCircle2 size={16} className="text-emerald-600 mt-0.5 shrink-0" />
                  ) : (
                    <XCircle size={16} className="text-red-500 mt-0.5 shrink-0" />
                  )}
                  <div className="flex-1 min-w-0">
                    <p className="text-sm text-slate-700">{claim.sentence}</p>
                    {claim.status === 'rejected' && claim.rejectionReason && (
                      <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-red-600">
                        <span className="font-medium">Rejected and removed from report</span>
                        <span>{claim.rejectionReason}</span>
                      </div>
                    )}
                  </div>
                  <span
                    className={`shrink-0 text-xs font-medium px-2 py-0.5 rounded-full border ${claim.status === 'supported' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-red-50 text-red-700 border-red-200'}`}
                  >
                    {claim.status === 'supported' ? 'Verified' : 'Rejected'}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Traceability panel (desktop) */}
      {activeClaim && (
        <div
          ref={panelRef}
          className="hidden lg:flex flex-col w-[340px] shrink-0 bg-white border border-slate-200 rounded-2xl overflow-hidden sticky top-0 self-start"
          style={{ maxHeight: 'calc(100vh - 2rem)' }}
        >
          <TraceabilityPanel claim={activeClaim} onClose={() => setActiveClaim(null)} />
        </div>
      )}

      {/* Traceability panel (mobile overlay) */}
      {activeClaim && (
        <div
          className="lg:hidden fixed inset-0 z-50 flex flex-col bg-black/40"
          onClick={() => setActiveClaim(null)}
        >
          <div
            className="mt-auto bg-white rounded-t-3xl max-h-[85vh] overflow-y-auto"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="w-10 h-1 bg-slate-300 rounded-full mx-auto mt-3 mb-1" />
            <TraceabilityPanel claim={activeClaim} onClose={() => setActiveClaim(null)} />
          </div>
        </div>
      )}
    </div>
  );
}
