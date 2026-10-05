import { useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { login, register } from '../services/api';
import logoImg from '../imports/data-speak-logo-green.png';

interface Props {
  onAuth: (user: { id: string; name: string; email: string }) => void;
}

interface TextFieldProps {
  id: string;
  label: string;
  type?: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
}

function TextField({ id, label, type = 'text', value, onChange, error }: TextFieldProps) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-slate-700 mb-1.5">
        {label}
      </label>
      <input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className={`w-full px-3.5 py-2.5 text-sm rounded-lg border bg-white transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-0
          ${error ? 'border-red-400 focus:ring-red-500' : 'border-slate-300 hover:border-slate-400'}`}
        aria-describedby={error ? `${id}-error` : undefined}
        aria-invalid={!!error}
      />
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}

interface PasswordFieldProps {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  show: boolean;
  onToggle: () => void;
  error?: string;
}

function PasswordField({ id, label, value, onChange, show, onToggle, error }: PasswordFieldProps) {
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
          onChange={(e) => onChange(e.target.value)}
          className={`w-full px-3.5 py-2.5 pr-11 text-sm rounded-lg border bg-white transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-0
            ${error ? 'border-red-400 focus:ring-red-500' : 'border-slate-300 hover:border-slate-400'}`}
          aria-describedby={error ? `${id}-error` : undefined}
          aria-invalid={!!error}
        />
        <button
          type="button"
          onMouseDown={(e) => e.preventDefault()}
          onClick={onToggle}
          className="absolute inset-y-0 right-0 px-3 flex items-center text-slate-500 hover:text-slate-700 focus:outline-none"
          aria-label={show ? 'Hide password' : 'Show password'}
        >
          {show ? <EyeOff size={16} /> : <Eye size={16} />}
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

export function LoginScreen({ onAuth }: Props) {
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  const validate = () => {
    const e: Record<string, string> = {};
    if (!email) e.email = 'Email is required';
    else if (!/\S+@\S+\.\S+/.test(email)) e.email = 'Enter a valid email address';
    if (!password) e.password = 'Password is required';
    else if (password.length < 6) e.password = 'Password must be at least 6 characters';
    if (mode === 'register') {
      if (!name.trim()) e.name = 'Name is required';
      if (confirm !== password) e.confirm = 'Passwords do not match';
    }
    return e;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const errs = validate();
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setLoading(true);
    try {
      const result =
        mode === 'login' ? await login(email, password) : await register(name, email, password);
      onAuth(result.user);
    } catch {
      setErrors({ form: 'Invalid credentials. Please try again.' });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-50 flex items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="bg-white border border-slate-200 rounded-2xl p-8 shadow-sm">
          {/* Logo + tagline */}
          <div className="flex flex-col items-center mb-7">
            <img
              src={logoImg}
              alt="Data Speak — Turning data into clearer decisions"
              className="h-20 w-auto object-contain"
            />
          </div>

          <h1 className="text-lg font-semibold text-slate-900 mb-5">
            {mode === 'login' ? 'Log in to your account' : 'Create an account'}
          </h1>

          <form onSubmit={handleSubmit} noValidate className="space-y-4">
            {errors.form && (
              <div className="bg-red-50 border border-red-200 rounded-lg px-3 py-2.5 text-sm text-red-700">
                {errors.form}
              </div>
            )}

            {mode === 'register' && (
              <TextField
                id="name"
                label="Full name"
                value={name}
                onChange={setName}
                error={errors.name}
              />
            )}
            <TextField
              id="email"
              label="Email address"
              type="email"
              value={email}
              onChange={setEmail}
              error={errors.email}
            />
            <PasswordField
              id="password"
              label="Password"
              value={password}
              onChange={setPassword}
              show={showPassword}
              onToggle={() => setShowPassword((v) => !v)}
              error={errors.password}
            />
            {mode === 'register' && (
              <PasswordField
                id="confirm"
                label="Confirm password"
                value={confirm}
                onChange={setConfirm}
                show={showConfirm}
                onToggle={() => setShowConfirm((v) => !v)}
                error={errors.confirm}
              />
            )}

            <button
              type="submit"
              disabled={loading}
              className="w-full mt-1 py-2.5 bg-brand-700 hover:bg-brand-600 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700 focus:ring-offset-2"
            >
              {loading
                ? mode === 'login'
                  ? 'Logging in…'
                  : 'Creating account…'
                : mode === 'login'
                  ? 'Log in'
                  : 'Create account'}
            </button>
          </form>

          <p className="mt-5 text-sm text-center text-slate-600">
            {mode === 'login' ? "Don't have an account? " : 'Already have an account? '}
            <button
              onClick={() => {
                setMode(mode === 'login' ? 'register' : 'login');
                setErrors({});
              }}
              className="text-brand-700 font-medium hover:underline focus:outline-none focus:ring-2 focus:ring-brand-700 rounded"
            >
              {mode === 'login' ? 'Register' : 'Log in'}
            </button>
          </p>
        </div>
      </div>
    </div>
  );
}
