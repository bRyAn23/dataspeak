// FE-PG-001 LoginPage: authenticate the user (AUTH-API-002).
import { useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { ApiError, describeError } from '@/services/apiClient';
import { Button } from '@/components/layout/Button';
import { AuthCard, FormError, PasswordField, TextField } from './AuthForm';
import { t } from '@/i18n';

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation() as { state?: { from?: string } };
  const [params] = useSearchParams();
  const verified = params.get('verified'); // set by the API after the email link was clicked
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const next: Record<string, string> = {};
    if (!email) next.email = t('Email is required.');
    if (!password) next.password = t('Password is required.');
    setErrors(next);
    if (Object.keys(next).length) return;
    setBusy(true);
    try {
      await login(email, password);
      navigate(location.state?.from ?? '/analyses', { replace: true });
    } catch (err) {
      if (err instanceof ApiError && err.status === 401)
        setErrors({ form: t('The email or password is incorrect.') });
      else if (err instanceof ApiError && err.status === 422)
        setErrors({ ...err.fieldErrors(), form: describeError(err) });
      else setErrors({ form: describeError(err) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthCard
      title={t('Log in to your account')}
      footer={
        <>
          {t("Don't have an account?")}{' '}
          <Link to="/register" className="text-brand-700 font-medium hover:underline">
            {t('Register')}
          </Link>
        </>
      }
    >
      <form onSubmit={submit} noValidate className="space-y-4">
        {verified === '1' || verified === 'already' ? (
          <p
            role="status"
            className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"
          >
            {t('Your email is confirmed. Log in to continue.')}
          </p>
        ) : null}
        {verified === 'invalid' ? (
          <p
            role="alert"
            className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
          >
            {t(
              'That confirmation link is invalid or has expired. Log in and choose "Send the email again".',
            )}
          </p>
        ) : null}
        <FormError message={errors.form} />
        <TextField
          id="email"
          label={t('Email address')}
          type="email"
          value={email}
          onChange={setEmail}
          error={errors.email}
          autoComplete="email"
        />
        <PasswordField
          id="password"
          label={t('Password')}
          value={password}
          onChange={setPassword}
          error={errors.password}
          autoComplete="current-password"
        />
        <Button type="submit" loading={busy} className="w-full mt-1">
          {t('Log in')}
        </Button>
      </form>
    </AuthCard>
  );
}
