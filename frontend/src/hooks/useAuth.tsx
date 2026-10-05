// FE-HOOK-002 useAuth: authentication/session abstraction over Sanctum bearer tokens.
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { authService, type RegisterInput } from '@/services/auth.service';
import { setUnauthorizedHandler } from '@/services/apiClient';
import { session } from '@/lib/session';
import { logger } from '@/lib/logger';
import type { User } from '@/types/analysis.types';

interface AuthContextValue {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (input: RegisterInput) => Promise<void>;
  logout: () => Promise<void>;
  /** Re-read /me (used after the user confirms their email in another tab). */
  refresh: () => Promise<User | null>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(() => session.token() !== null);

  const clear = useCallback(() => {
    session.clear();
    setUser(null);
    queryClient.clear();
  }, [queryClient]);

  useEffect(() => {
    setUnauthorizedHandler(() => clear());
    return () => setUnauthorizedHandler(null);
  }, [clear]);

  // Restore the session on refresh by asking the API who we are.
  useEffect(() => {
    if (!session.token()) return;
    let cancelled = false;
    authService
      .me()
      .then((me) => {
        if (!cancelled) setUser(me);
      })
      .catch(() => {
        if (!cancelled) clear();
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [clear]);

  const login = useCallback(async (email: string, password: string) => {
    const result = await authService.login(email, password);
    session.set({ token: result.token, userId: result.user.id });
    setUser(result.user);
    logger.info('auth.login', { user_id: result.user.id });
  }, []);

  const register = useCallback(async (input: RegisterInput) => {
    const result = await authService.register(input);
    session.set({ token: result.token, userId: result.user.id });
    setUser(result.user);
  }, []);

  const refresh = useCallback(async () => {
    if (!session.token()) return null;
    try {
      const me = await authService.me();
      setUser(me);
      return me;
    } catch {
      return null;
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await authService.logout();
    } catch {
      /* token may already be invalid */
    }
    clear();
  }, [clear]);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      isAuthenticated: user !== null,
      isLoading,
      login,
      register,
      logout,
      refresh,
    }),
    [user, isLoading, login, register, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
