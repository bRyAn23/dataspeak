// FE-ANL-002 AnalysisProgress: stage/progress with an aria-live region that announces stage changes only.
import { useEffect, useRef, useState } from 'react';
import { Check, Loader2 } from 'lucide-react';
import type { AnalysisStage, StatusResponse } from '@/types/analysis.types';
import { stageLabel } from '@/utils/statusLabels';
import { cn } from '@/lib/cn';
import { t } from '@/i18n';

const PROFILE_STAGES: AnalysisStage[] = ['PROFILING', 'DEFINITION'];
const RUN_STAGES: AnalysisStage[] = ['STATISTICAL_ANALYSIS', 'INSIGHT_DETECTION', 'VISUALIZATION'];
const REPORT_STAGES: AnalysisStage[] = ['AI_SYNTHESIS', 'CLAIM_VALIDATION', 'REPORTING'];

// Stages that wait on the AI model: the bar cannot move while a single model call runs, so say what is happening.
const MODEL_STAGE_HINTS: Partial<Record<AnalysisStage, string>> = {
  DEFINITION: 'The AI model is suggesting column meanings. This usually takes under a minute.',
  AI_SYNTHESIS:
    'The AI model is writing the narrative from the calculated evidence. This usually takes one to two minutes; the bar moves when it finishes.',
  CLAIM_VALIDATION: 'Every statement is being checked against the evidence.',
};
const FULL_STAGES: AnalysisStage[] = [...RUN_STAGES, ...REPORT_STAGES];

export function AnalysisProgress({
  status,
  mode,
}: {
  status: StatusResponse;
  mode: 'profile' | 'run' | 'report' | 'full';
}) {
  const stages =
    mode === 'profile'
      ? PROFILE_STAGES
      : mode === 'run'
        ? RUN_STAGES
        : mode === 'report'
          ? REPORT_STAGES
          : FULL_STAGES;
  const currentStage = (status.job?.stage as AnalysisStage | null) ?? status.stage;
  const currentIndex = Math.max(0, stages.indexOf(currentStage));
  const failed = status.status === 'FAILED' || status.job?.status === 'FAILED';
  const done =
    status.is_terminal ||
    failed ||
    (mode === 'run' && status.status === 'ANALYZED') ||
    (mode === 'profile' && status.status !== 'VALIDATED');
  // Overall percentage across the stages shown: completed stages plus progress within the current one.
  const overall = done
    ? 100
    : Math.min(
        99,
        Math.round(((currentIndex + Math.min(status.progress, 99) / 100) / stages.length) * 100),
      );
  const [announcement, setAnnouncement] = useState('');
  const lastStage = useRef<string | null>(null);

  useEffect(() => {
    const key = done ? `done:${status.status}` : currentStage;
    if (key !== lastStage.current) {
      lastStage.current = key;
      setAnnouncement(
        done
          ? failed
            ? t('The step stopped with a problem.')
            : t('Finished.')
          : `${stageLabel(currentStage)}.`,
      );
    }
  }, [currentStage, done, failed, status.status]);

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6">
      <div className="flex items-center justify-between text-sm">
        <span className="font-medium text-slate-800">
          {done ? (failed ? t('Stopped') : t('Finished')) : stageLabel(currentStage)}
        </span>
        <span className="tabular-nums text-slate-500">{overall}%</span>
      </div>
      <div
        className="mt-2 h-2 rounded-full bg-slate-100"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={status.progress}
        aria-label={t('Analysis progress')}
      >
        <div
          className={cn(
            'h-full rounded-full transition-all duration-300',
            failed ? 'bg-red-500' : 'bg-brand-700',
          )}
          style={{ width: `${overall}%` }}
        />
      </div>
      {!done && MODEL_STAGE_HINTS[currentStage] && (
        <p className="mt-2 text-xs text-slate-500">{t(MODEL_STAGE_HINTS[currentStage])}</p>
      )}
      <ol className="mt-5 space-y-2">
        {stages.map((stage, i) => {
          const state = failed
            ? i < currentIndex
              ? 'done'
              : 'waiting'
            : done
              ? 'done'
              : i < currentIndex
                ? 'done'
                : i === currentIndex
                  ? 'active'
                  : 'waiting';
          return (
            <li key={stage} className="flex items-center gap-3 text-sm">
              <span
                className={cn(
                  'w-6 h-6 rounded-full flex items-center justify-center border',
                  state === 'done'
                    ? 'bg-brand-700 border-brand-700 text-white'
                    : state === 'active'
                      ? 'border-brand-700 text-brand-700'
                      : 'border-slate-200 text-slate-300',
                )}
                aria-hidden="true"
              >
                {state === 'done' ? (
                  <Check size={12} strokeWidth={3} />
                ) : state === 'active' ? (
                  <Loader2 size={12} className="animate-spin" />
                ) : (
                  i + 1
                )}
              </span>
              <span className={state === 'waiting' ? 'text-slate-400' : 'text-slate-700'}>
                {stageLabel(stage)}
              </span>
            </li>
          );
        })}
      </ol>
      <p className="sr-only" aria-live="polite" aria-atomic="true">
        {announcement}
      </p>
    </div>
  );
}
