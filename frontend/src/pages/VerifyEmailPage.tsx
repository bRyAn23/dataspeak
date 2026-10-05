// FE-PG-010 VerifyEmailPage: shown after sign-up (and to any unverified login) until the email link is clicked.
import { useState } from 'react';
import { MailCheck } from 'lucide-react';
import { Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { authService } from '@/services/auth.service';
import { ApiError, describeError } from '@/services/apiClient';
import { Button } from '@/components/layout/Button';
import { AuthCard, FormError } from './AuthForm';
import { t } from '@/i18n';

export function VerifyEmailPage() {
  const { user, refresh, logout } = useAuth();
  const navigate = useNavigate();
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | undefined>(undefined);
  const [busy, setBusy] = useState<'resend' | 'check' | null>(null);

  if (user?.email_verified) return <Navigate to="/analyses" replace />;

  const resend = async () => {
    setBusy('resend');
    setError(undefined);
    setNotice(null);
    try {
      const result = await authService.resendVerification();
      if (result.status === 'already_verified') {
        await refresh();
        navigate('/analyses', { replace: true });
        return;
      }
      setNotice(
        t('We sent a new confirmation email to {email}.', {
          email: user?.email ?? t('your address'),
        }),
      );
    } catch (err) {
      if (err instanceof ApiError && err.status === 429)
        setError(
          t('You asked for the email a moment ago. Please wait a minute before trying again.'),
        );
      else if (err instanceof ApiError && err.status === 503)
        setError(t('The email could not be sent right now. Please try again in a few minutes.'));
      else setError(describeError(err));
    } finally {
      setBusy(null);
    }
  };

  const check = async () => {
    setBusy('check');
    setError(undefined);
    const me = await refresh();
    setBusy(null);
    if (me?.email_verified) navigate('/analyses', { replace: true });
    else
      setNotice(t('Not confirmed yet. Open the email from Data Speak and click the button in it.'));
  };

  return (
    <AuthCard
      title={t('Confirm your email')}
      footer={
        <>
          {t('Wrong address?')}{' '}
          <button
            type="button"
            onClick={() => logout()}
            className="text-brand-700 font-medium hover:underline"
          >
            {t('Log out and register again')}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="flex items-start gap-3 rounded-lg bg-brand-50 p-4 text-sm text-slate-800">
          <MailCheck size={20} className="mt-0.5 shrink-0 text-brand-700" aria-hidden="true" />
          <p>
            {t('We sent a confirmation link to')} <strong>{user?.email}</strong>.{' '}
            {t(
              'Click the button in that email, then come back here. The link works for 60 minutes.',
            )}
          </p>
        </div>
        <FormError message={error} />
        {notice ? (
          <p role="status" className="text-sm text-slate-600">
            {notice}
          </p>
        ) : null}
        <Button type="button" onClick={check} loading={busy === 'check'} className="w-full">
          {t('I have confirmed my email')}
        </Button>
        <Button
          type="button"
          variant="secondary"
          onClick={resend}
          loading={busy === 'resend'}
          className="w-full"
        >
          {t('Send the email again')}
        </Button>
      </div>
    </AuthCard>
  );
}
