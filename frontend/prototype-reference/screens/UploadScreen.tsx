import { useState, useRef } from 'react';
import { Upload, FileText, X, AlertCircle, Lock, Database } from 'lucide-react';

interface Props {
  onNext: (analysisId: string, name: string) => void;
  onCancel: () => void;
}

const ACCEPTED_EXTS = ['.csv', '.xlsx', '.xls'];

function isAcceptedFile(f: File): boolean {
  const lower = f.name.toLowerCase();
  return ACCEPTED_EXTS.some((ext) => lower.endsWith(ext));
}

function nameFromFilename(filename: string): string {
  return filename
    .replace(/\.(csv|xlsx|xls)$/i, '')
    .replace(/[-_]/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

const SAMPLE_NAME = 'Retail Sales 2025';

export function UploadScreen({ onNext, onCancel }: Props) {
  const [file, setFile] = useState<File | null>(null);
  const [name, setName] = useState('');
  const [dragging, setDragging] = useState(false);
  const [progress, setProgress] = useState(0);
  const [uploading, setUploading] = useState(false);
  const [fileError, setFileError] = useState('');
  const [nameError, setNameError] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);

  const handleFile = (f: File) => {
    setFileError('');
    if (!isAcceptedFile(f)) {
      setFileError('Only CSV and Excel files (.csv, .xlsx, .xls) are supported.');
      return;
    }
    if (f.size > 50 * 1024 * 1024) {
      setFileError('File is too large. Maximum size is 50 MB.');
      return;
    }
    setFile(f);
    if (!name.trim()) setName(nameFromFilename(f.name));
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setDragging(false);
    const f = e.dataTransfer.files[0];
    if (f) handleFile(f);
  };

  const handleUpload = async () => {
    let valid = true;
    if (!file) {
      setFileError('Please select a CSV file to upload.');
      valid = false;
    }
    if (!name.trim()) {
      setNameError('Analysis name is required.');
      valid = false;
    }
    if (!valid) return;

    setUploading(true);
    const interval = setInterval(() => setProgress((p) => Math.min(p + 10, 90)), 120);
    await new Promise((r) => setTimeout(r, 1400));
    clearInterval(interval);
    setProgress(100);
    await new Promise((r) => setTimeout(r, 300));
    onNext('a_new', name.trim());
  };

  const handleSample = async () => {
    setUploading(true);
    setName(SAMPLE_NAME);
    const interval = setInterval(() => setProgress((p) => Math.min(p + 18, 90)), 100);
    await new Promise((r) => setTimeout(r, 700));
    clearInterval(interval);
    setProgress(100);
    await new Promise((r) => setTimeout(r, 200));
    onNext('a_new', SAMPLE_NAME);
  };

  return (
    <div className="max-w-lg mx-auto">
      {/* Drop zone */}
      <div
        onDragOver={(e) => {
          e.preventDefault();
          setDragging(true);
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={handleDrop}
        onClick={() => !file && inputRef.current?.click()}
        className={`border-2 border-dashed rounded-2xl p-10 text-center transition-colors mb-2
          ${dragging ? 'border-brand-600 bg-brand-50' : file ? 'border-slate-200 bg-slate-50 cursor-default' : 'border-slate-300 hover:border-brand-500 hover:bg-brand-50/30 cursor-pointer'}`}
      >
        <input
          ref={inputRef}
          type="file"
          accept=".csv,.xlsx,.xls"
          className="hidden"
          onChange={(e) => {
            if (e.target.files?.[0]) handleFile(e.target.files[0]);
          }}
          aria-label="Upload CSV file"
        />
        {file ? (
          <div className="flex items-center gap-3 justify-center">
            <div className="w-10 h-10 bg-brand-100 rounded-xl flex items-center justify-center shrink-0">
              <FileText size={20} className="text-brand-700" />
            </div>
            <div className="text-left min-w-0">
              <p className="text-sm font-medium text-slate-800 truncate">{file.name}</p>
              <p className="text-xs text-slate-600">{(file.size / 1024 / 1024).toFixed(2)} MB</p>
            </div>
            <button
              onClick={(e) => {
                e.stopPropagation();
                setFile(null);
                setProgress(0);
                setUploading(false);
                setFileError('');
              }}
              className="ml-2 p-1 hover:bg-slate-200 rounded-lg text-slate-500 transition-colors shrink-0"
              aria-label="Remove file"
            >
              <X size={16} />
            </button>
          </div>
        ) : (
          <>
            <div className="w-12 h-12 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
              <Upload size={22} className="text-slate-500" />
            </div>
            <p className="text-sm font-medium text-slate-700">
              Drop a file here or <span className="text-brand-700 underline">browse</span>
            </p>
            <p className="mt-1.5 text-xs text-slate-600">CSV · Excel (.xlsx, .xls) · up to 50 MB</p>
          </>
        )}
      </div>

      {/* Privacy note */}
      <div className="flex items-center gap-1.5 text-xs text-slate-600 mb-5 px-1">
        <Lock size={11} className="shrink-0 text-slate-500" />
        Your raw file is deleted automatically after analysis.
      </div>

      {fileError && (
        <div className="mb-4 flex items-start gap-2 bg-red-50 border border-red-200 rounded-xl px-3.5 py-3 text-sm text-red-700">
          <AlertCircle size={15} className="mt-0.5 shrink-0" />
          {fileError}
        </div>
      )}

      {uploading && (
        <div className="mb-5">
          <div className="flex items-center justify-between text-xs text-slate-600 mb-1.5">
            <span>{file?.name ?? 'Sample data'}</span>
            <span>{progress}%</span>
          </div>
          <div className="h-1.5 bg-slate-200 rounded-full overflow-hidden">
            <div
              className="h-full bg-brand-700 rounded-full transition-all duration-150"
              style={{ width: `${progress}%` }}
            />
          </div>
        </div>
      )}

      {/* Analysis name */}
      <div className="mb-6">
        <label htmlFor="analysis-name" className="block text-sm font-medium text-slate-700 mb-1.5">
          Analysis name
        </label>
        <input
          id="analysis-name"
          type="text"
          value={name}
          onChange={(e) => {
            setName(e.target.value);
            setNameError('');
          }}
          placeholder="e.g. Retail Sales 2025"
          className={`w-full px-3.5 py-2.5 text-sm rounded-lg border bg-white transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700
            ${nameError ? 'border-red-400' : 'border-slate-300 hover:border-slate-400'}`}
        />
        {nameError && <p className="mt-1 text-xs text-red-600">{nameError}</p>}
      </div>

      {/* Actions */}
      <div className="flex flex-wrap gap-3 items-center">
        <button
          onClick={handleUpload}
          disabled={uploading}
          className="px-5 py-2.5 bg-brand-700 hover:bg-brand-600 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
        >
          {uploading ? 'Uploading…' : 'Upload and continue'}
        </button>
        <button
          onClick={handleSample}
          disabled={uploading}
          className="flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-300 hover:bg-slate-50 disabled:opacity-60 text-slate-700 text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
        >
          <Database size={15} />
          Try with sample data
        </button>
        <button
          onClick={onCancel}
          className="text-sm text-slate-600 hover:text-slate-800 hover:underline focus:outline-none focus:ring-2 focus:ring-brand-700 rounded px-2 py-1 transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}
