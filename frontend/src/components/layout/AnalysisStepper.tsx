// FE-CMP-003 AnalysisStepper: Upload -> Profile -> Define -> Analyze -> Report, gated by real status.
import { Check } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { STEPS, furthestStep, stepPath } from '@/utils/steps';
import type { AnalysisStatus } from '@/types/analysis.types';
import { cn } from '@/lib/cn';
import { t } from '@/i18n';

interface Props {
  current: number;
  analysisId?: string;
  status?: AnalysisStatus;
}

export function AnalysisStepper({ current, analysisId, status }: Props) {
  const navigate = useNavigate();
  const furthest = status ? furthestStep(status) : current;

  return (
    <nav aria-label={t('Analysis steps')} className="flex justify-center mb-8 overflow-x-auto pb-2">
      <ol className="flex items-center">
        {STEPS.map(({ step, label: english }, i) => {
          const label = t(english);
          const done = step < current;
          const active = step === current;
          const allowed = Boolean(analysisId) && step <= furthest && !active;
          return (
            <li key={step} className="flex items-center">
              <div className="flex flex-col items-center">
                <button
                  type="button"
                  onClick={() => allowed && analysisId && navigate(stepPath(analysisId, step))}
                  disabled={!allowed}
                  aria-current={active ? 'step' : undefined}
                  aria-label={
                    allowed
                      ? t('Go to {label}', { label })
                      : `${label}${done ? t(', completed') : active ? t(', current step') : t(', not available yet')}`
                  }
                  className={cn(
                    'w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium border-2 transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2',
                    done || (allowed && step <= furthest && !active && step < current)
                      ? 'bg-brand-700 border-brand-700 text-white'
                      : active
                        ? 'bg-white border-brand-700 text-brand-700'
                        : allowed
                          ? 'bg-white border-brand-300 text-brand-700'
                          : 'bg-white border-slate-200 text-slate-400',
                    allowed ? 'cursor-pointer hover:bg-brand-50' : 'cursor-default',
                  )}
                >
                  {done ? <Check size={14} strokeWidth={2.5} aria-hidden="true" /> : step}
                </button>
                <span
                  className={cn(
                    'mt-1.5 text-xs font-medium',
                    active ? 'text-brand-700' : done ? 'text-slate-700' : 'text-slate-400',
                  )}
                >
                  {label}
                </span>
              </div>
              {i < STEPS.length - 1 && (
                <div
                  className={cn(
                    'h-px w-10 sm:w-16 mx-1 mb-4',
                    done ? 'bg-brand-700' : 'bg-slate-200',
                  )}
                  aria-hidden="true"
                />
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
