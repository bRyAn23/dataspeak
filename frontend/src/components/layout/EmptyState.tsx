import { FileBarChart2 } from 'lucide-react';
import type { ReactNode } from 'react';

export function EmptyState({
  title,
  body,
  action,
}: {
  title: string;
  body?: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-center justify-center py-20 text-center">
      <div className="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center mb-4">
        <FileBarChart2 size={28} className="text-slate-400" aria-hidden="true" />
      </div>
      <h3 className="text-lg font-semibold text-slate-800">{title}</h3>
      {body && <p className="mt-1.5 text-sm text-slate-500 max-w-sm">{body}</p>}
      {action && <div className="mt-6">{action}</div>}
    </div>
  );
}
