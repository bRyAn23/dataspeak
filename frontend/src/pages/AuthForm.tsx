// Shared form pieces for LoginPage and RegisterPage.
import { useState, type ReactNode } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import logo from '@/assets/logo.png';
import { LanguageSwitcher } from '@/components/layout/LanguageSwitcher';
import { t } from '@/i18n';

export function AuthCard({
  title,
  children,
  footer,
}: {
  title: string;
  children: ReactNode;
  footer: ReactNode;
}) {
  return (
    <div className="min-h-screen bg-slate-50 flex items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="mb-3 flex justify-end">
          <LanguageSwitcher compact />
        </div>
        <div className="bg-white border border-slate-200 rounded-2xl p-8 shadow-sm">
          <div className="flex flex-col items-center mb-7">
            <img
              src={logo}
              alt={t('Data Speak, turning data into clearer decisions')}
              className="h-20 w-auto object-contain"
            />
          </div>
          <h1 className="text-lg font-semibold text-slate-900 mb-5">{title}</h1>
          {children}
          <p className="mt-5 text-sm text-center text-slate-600">{footer}</p>
        </div>
      </div>
    </div>
  );
}

interface FieldProps {
  id: string;
  label: string;
  type?: string;
  value: string;
  onChange: (v: string) => void;
  error?: string;
  autoComplete?: string;
}

export function TextField({
  id,
  label,
  type = 'text',
  value,
  onChange,
  error,
  autoComplete,
}: FieldProps) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-slate-700 mb-1.5">
        {label}
      </label>
      <input
        id={id}
        type={type}
        value={value}
        autoComplete={autoComplete}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={Boolean(error)}
        aria-describedby={error ? `${id}-error` : undefined}
        className={`w-full px-3.5 py-2.5 text-sm rounded-lg border bg-white focus:outline-none focus:ring-2 ${error ? 'border-red-400 focus:ring-red-500' : 'border-slate-300 focus:ring-brand-700'}`}
      />
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}

export function PasswordField({ id, label, value, onChange, error, autoComplete }: FieldProps) {
  const [show, setShow] = useState(false);
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-slate-700 mb-1.5">
        {label}
      </label>
      <div className="relative">
        <input
          id={id}
          type={show ? 'text' : 'password'}
          value={value}
          autoComplete={autoComplete}
          onChange={(e) => onChange(e.target.value)}
          aria-invalid={Boolean(error)}
          aria-describedby={error ? `${id}-error` : undefined}
          className={`w-full px-3.5 py-2.5 pr-11 text-sm rounded-lg border bg-white focus:outline-none focus:ring-2 ${error ? 'border-red-400 focus:ring-red-500' : 'border-slate-300 focus:ring-brand-700'}`}
        />
        <button
          type="button"
          onClick={() => setShow((v) => !v)}
          className="absolute inset-y-0 right-0 px-3 flex items-center text-slate-500 hover:text-slate-700"
          aria-label={show ? t('Hide password') : t('Show password')}
        >
          {show ? <EyeOff size={16} aria-hidden="true" /> : <Eye size={16} aria-hidden="true" />}
        </button>
      </div>
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}

export function FormError({ message }: { message?: string }) {
  if (!message) return null;
  return (
    <div
      role="alert"
      className="bg-red-50 border border-red-200 rounded-lg px-3 py-2.5 text-sm text-red-700"
    >
      {message}
    </div>
  );
}
