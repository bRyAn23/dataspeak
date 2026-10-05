import { FileBarChart2 } from 'lucide-react';

export function EmptyState({ onNew }: { onNew: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center py-24 text-center">
      <div className="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center mb-4">
        <FileBarChart2 size={28} className="text-slate-400" />
      </div>
      <h3 className="text-lg font-semibold text-slate-800">No analyses yet</h3>
      <p className="mt-1.5 text-sm text-slate-500 max-w-xs">
        Upload a CSV file and Data Speak will profile it, verify the numbers, and write you a
        trustworthy report.
      </p>
      <button
        onClick={onNew}
        className="mt-6 px-5 py-2.5 bg-brand-700 hover:bg-brand-600 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
      >
        Start your first analysis
      </button>
    </div>
  );
}
