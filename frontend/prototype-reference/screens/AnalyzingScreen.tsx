import { useState, useEffect, useRef } from 'react';
import { Check, Loader2, Circle, ShieldCheck, AlertCircle, ChevronRight } from 'lucide-react';

interface Props {
  analysisName: string;
  onDone: () => void;
  onBack: () => void;
}

type StageState = 'waiting' | 'running' | 'done' | 'failed';

interface Stage {
  label: string;
  result: string;
  duration: number; // ms
}

const STAGES: Stage[] = [
  { label: 'Statistical analysis', result: '18 calculations completed', duration: 1600 },
  { label: 'Finding key insights', result: '7 insights found, top 4 selected', duration: 1800 },
  { label: 'Choosing charts', result: '4 charts chosen', duration: 1000 },
  { label: 'Writing narrative', result: '12 statements written', duration: 2000 },
  {
    label: 'Verifying claims',
    result: '12 of 12 verified · 1 corrected and re-checked',
    duration: 2200,
  },
  { label: 'Building report', result: 'Report assembled and ready', duration: 900 },
];

const TOTAL_MS = STAGES.reduce((s, st) => s + st.duration, 0);

export function AnalyzingScreen({ analysisName, onDone, onBack }: Props) {
  const [stageStates, setStageStates] = useState<StageState[]>(STAGES.map(() => 'waiting'));
  const [progress, setProgress] = useState(0);
  const [complete, setComplete] = useState(false);
  const [failed, setFailed] = useState(false);
  const startedRef = useRef(false);

  useEffect(() => {
    if (startedRef.current) return;
    startedRef.current = true;

    let elapsed = 0;
    const run = async () => {
      for (let i = 0; i < STAGES.length; i++) {
        setStageStates((prev) => prev.map((s, j) => (j === i ? 'running' : s)));

        // Simulate progress ticks during this stage
        const ticks = 10;
        const tick = STAGES[i].duration / ticks;
        for (let t = 0; t < ticks; t++) {
          await delay(tick);
          elapsed += tick;
          setProgress(Math.round((elapsed / TOTAL_MS) * 100));
        }

        setStageStates((prev) => prev.map((s, j) => (j === i ? 'done' : s)));
      }
      setProgress(100);
      setComplete(true);
    };

    run();
  }, []);

  const currentStage = stageStates.filter((s) => s === 'done').length;

  const handleRetry = () => {
    setFailed(false);
    setStageStates(STAGES.map(() => 'waiting'));
    setProgress(0);
    setComplete(false);
    startedRef.current = false;
  };

  return (
    <div className="px-6 sm:px-8 pb-12 flex justify-center">
      <div className="w-full max-w-md">
        {/* Header card */}
        <div className="bg-white border border-slate-200 rounded-2xl p-8 mb-5 text-center">
          <h1 className="text-lg font-semibold text-slate-900 mb-1 truncate">{analysisName}</h1>

          {complete ? (
            <div className="mt-4">
              <div className="w-14 h-14 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-3">
                <Check size={28} className="text-emerald-600" strokeWidth={2.5} />
              </div>
              <p className="text-base font-semibold text-slate-900">Your report is ready</p>
              <p className="text-sm text-slate-600 mt-1">All 12 claims verified · 1 corrected</p>
              <button
                onClick={onDone}
                className="mt-5 inline-flex items-center gap-2 px-6 py-2.5 bg-brand-700 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
              >
                View report
                <ChevronRight size={15} />
              </button>
            </div>
          ) : failed ? (
            <div className="mt-2">
              <p className="text-sm text-slate-600">
                Step {currentStage} of {STAGES.length}
              </p>
              <div className="mt-4 h-1.5 bg-slate-200 rounded-full overflow-hidden mb-5">
                <div
                  className="h-full bg-red-500 rounded-full transition-all duration-300"
                  style={{ width: `${progress}%` }}
                />
              </div>
            </div>
          ) : (
            <div className="mt-2">
              <p className="text-sm text-slate-600">
                Step {Math.min(currentStage + 1, STAGES.length)} of {STAGES.length} · Usually under
                a minute
              </p>
              <div className="mt-4 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                <div
                  className="h-full bg-brand-700 rounded-full transition-all duration-300"
                  style={{ width: `${progress}%` }}
                />
              </div>
            </div>
          )}
        </div>

        {/* Stages checklist */}
        <div className="bg-white border border-slate-200 rounded-2xl overflow-hidden mb-5">
          <div className="divide-y divide-slate-100">
            {STAGES.map((stage, i) => {
              const state = stageStates[i];
              const isFailed = failed && state === 'running';

              return (
                <div key={i} className="px-5 py-4 flex items-start gap-3">
                  {/* Status icon */}
                  <div className="mt-0.5 shrink-0">
                    {isFailed ? (
                      <div className="w-5 h-5 rounded-full bg-red-100 flex items-center justify-center">
                        <AlertCircle size={12} className="text-red-600" />
                      </div>
                    ) : state === 'done' ? (
                      <div className="w-5 h-5 rounded-full bg-emerald-500 flex items-center justify-center">
                        <Check size={11} className="text-white" strokeWidth={2.5} />
                      </div>
                    ) : state === 'running' ? (
                      <Loader2 size={20} className="text-brand-700 animate-spin" />
                    ) : (
                      <Circle size={20} className="text-slate-300" />
                    )}
                  </div>

                  <div className="flex-1 min-w-0">
                    <p
                      className={`text-sm font-medium ${
                        isFailed
                          ? 'text-red-700'
                          : state === 'done'
                            ? 'text-slate-800'
                            : state === 'running'
                              ? 'text-brand-700'
                              : 'text-slate-400'
                      }`}
                    >
                      {stage.label}
                    </p>

                    {state === 'done' && !isFailed && (
                      <p className="text-xs text-slate-600 mt-0.5">{stage.result}</p>
                    )}

                    {isFailed && i === 3 && (
                      <p className="text-xs text-red-600 mt-0.5">
                        The AI service is unavailable right now. Your calculated results are saved.
                      </p>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {/* Failure actions */}
        {failed && (
          <div className="flex gap-3 mb-5">
            <button
              onClick={handleRetry}
              className="flex-1 px-5 py-2.5 bg-brand-700 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
            >
              Retry from this step
            </button>
            <button
              onClick={onBack}
              className="px-5 py-2.5 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
            >
              Back to analyses
            </button>
          </div>
        )}

        {/* Trust line */}
        {!complete && !failed && (
          <div className="flex items-center gap-2 text-xs text-slate-600 justify-center mb-3">
            <ShieldCheck size={13} className="text-emerald-600 shrink-0" />
            Every statement in your report is checked against the raw numbers before publishing.
          </div>
        )}

        {/* Leave page note */}
        {!complete && !failed && (
          <p className="text-xs text-slate-500 text-center">
            You can leave this page — we'll email you when the report is ready.
          </p>
        )}
      </div>
    </div>
  );
}

function delay(ms: number) {
  return new Promise((r) => setTimeout(r, ms));
}
