import { Check } from 'lucide-react';

const STEPS = ['Upload', 'Profile', 'Define', 'Analyze', 'Report'];

interface StepperProps {
  current: number;
  onNavigate?: (step: number) => void;
}

export function Stepper({ current, onNavigate }: StepperProps) {
  return (
    <div className="flex items-center gap-0">
      {STEPS.map((label, i) => {
        const step = i + 1;
        const done = step < current;
        const active = step === current;
        const clickable = done && !!onNavigate;

        return (
          <div key={step} className="flex items-center">
            <div className="flex flex-col items-center">
              <button
                onClick={() => clickable && onNavigate?.(step)}
                disabled={!clickable}
                className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium border-2 transition-colors
                  ${
                    done
                      ? 'bg-brand-700 border-brand-700 text-white hover:bg-brand-600 hover:border-brand-600'
                      : active
                        ? 'bg-white border-brand-700 text-brand-700'
                        : 'bg-white border-slate-200 text-slate-400'
                  }
                  ${clickable ? 'cursor-pointer' : 'cursor-default'}`}
                aria-label={clickable ? `Go to ${label}` : label}
                aria-current={active ? 'step' : undefined}
              >
                {done ? <Check size={14} strokeWidth={2.5} /> : step}
              </button>
              <span
                className={`mt-1.5 text-xs font-medium ${active ? 'text-brand-700' : done ? 'text-slate-700' : 'text-slate-400'}`}
              >
                {label}
              </span>
            </div>
            {i < STEPS.length - 1 && (
              <div
                className={`h-px w-12 sm:w-16 mx-1 mb-4 ${done ? 'bg-brand-700' : 'bg-slate-200'}`}
              />
            )}
          </div>
        );
      })}
    </div>
  );
}
