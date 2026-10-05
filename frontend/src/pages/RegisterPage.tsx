// FE-PG-002 RegisterPage: create a user account (AUTH-API-001); a confirmation email follows (FE-PG-010).
import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { ApiError, describeError } from '@/services/apiClient';
import { Button } from '@/components/layout/Button';
import { AuthCard, FormError, PasswordField, TextField } from './AuthForm';
import { t } from '@/i18n';

export function RegisterPage() {
  const { register } = useAuth();
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const next: Record<string, string> = {};
    if (!name.trim()) next.name = t('Name is required.');
    if (!email) next.email = t('Email is required.');
    if (password.length < 8) next.password = t('Password must be at least 8 characters.');
    if (confirm !== password) next.password_confirmation = t('Passwords do not match.');
    setErrors(next);
    if (Object.keys(next).length) return;
    setBusy(true);
    try {
      await register({ name: name.trim(), email, password, password_confirmation: confirm });
      navigate('/verify-email', { replace: true }); // the account exists; the email must be confirmed first
    } catch (err) {
      if (err instanceof ApiError && err.status === 422)
        setErrors({ ...err.fieldErrors(), form: t('Please check the highlighted fields.') });
      else setErrors({ form: describeError(err) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthCard
      title={t('Create an account')}
      footer={
        <>
          {t('Already have an account?')}{' '}
          <Link to="/login" className="text-brand-700 font-medium hover:underline">
            {t('Log in')}
          </Link>
        </>
      }
    >
      <form onSubmit={submit} noValidate className="space-y-4">
        <FormError message={errors.form} />
        <TextField
          id="name"
          label={t('Full name')}
          value={name}
          onChange={setName}
          error={errors.name}
          autoComplete="name"
        />
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
          autoComplete="new-password"
        />
        <PasswordField
          id="confirm"
          label={t('Confirm password')}
          value={confirm}
          onChange={setConfirm}
          error={errors.password_confirmation}
          autoComplete="new-password"
        />
        <Button type="submit" loading={busy} className="w-full mt-1">
          {t('Create account')}
        </Button>
      </form>
    </AuthCard>
  );
}
