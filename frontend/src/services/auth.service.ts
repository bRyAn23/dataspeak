// FE-SVC-002 auth.service.ts: AUTH-API-001..006
import { api } from './apiClient';
import type { AuthResponse, User } from '@/types/analysis.types';
import type { Envelope } from '@/types/api.types';

export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export const authService = {
  register: (input: RegisterInput) =>
    api.post<Envelope<AuthResponse>>('/auth/register', input).then((r) => r.data),
  login: (email: string, password: string) =>
    api.post<Envelope<AuthResponse>>('/auth/login', { email, password }).then((r) => r.data),
  logout: () => api.post<Envelope<{ revoked: boolean }>>('/auth/logout').then((r) => r.data),
  me: () => api.get<Envelope<User>>('/me').then((r) => r.data),
  /** AUTH-API-006: send the confirmation email again (3 per minute). */
  resendVerification: () =>
    api
      .post<Envelope<{ status: 'sent' | 'already_verified' | 'failed'; email?: string }>>(
        '/auth/email/resend',
      )
      .then((r) => r.data),
};
