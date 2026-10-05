import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { LoadingState } from '@/components/layout/LoadingState';
import { t } from '@/i18n';

export function RequireAuth() {
  const { isAuthenticated, isLoading } = useAuth();
  const location = useLocation();
  if (isLoading) return <LoadingState label={t('Restoring your session…')} />;
  if (!isAuthenticated) return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  return <Outlet />;
}

/** Logged in but the email is not confirmed yet: everything except /verify-email redirects there. */
export function RequireVerified() {
  const { user } = useAuth();
  if (user && !user.email_verified) return <Navigate to="/verify-email" replace />;
  return <Outlet />;
}

export function RedirectIfAuthenticated() {
  const { isAuthenticated, isLoading } = useAuth();
  if (isLoading) return <LoadingState />;
  if (isAuthenticated) return <Navigate to="/analyses" replace />;
  return <Outlet />;
}
