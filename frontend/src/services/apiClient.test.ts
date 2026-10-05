import { describe, expect, it } from 'vitest';
import { HttpResponse, http } from 'msw';
import { ApiError, api, describeError } from './apiClient';
import { server } from '@/test/msw/server';
import { BASE } from '@/test/msw/handlers';
import { session } from '@/lib/session';

describe('apiClient (FE-SVC-001)', () => {
  it('sends the bearer token and Accept header', async () => {
    session.set({ token: 'tok-123', userId: '1' });
    let seen: Headers | null = null;
    server.use(
      http.get(`${BASE}/ping`, ({ request }) => {
        seen = request.headers;
        return HttpResponse.json({ data: { ok: true } });
      }),
    );
    await api.get(`/ping`);
    expect(seen!.get('authorization')).toBe('Bearer tok-123');
    expect(seen!.get('accept')).toBe('application/json');
  });

  it('normalises the error envelope into ApiError with field errors', async () => {
    server.use(
      http.post(`${BASE}/thing`, () =>
        HttpResponse.json(
          {
            error: {
              code: 'VALIDATION_FAILED',
              message: 'Nope',
              details: [{ field: 'name', issue: 'Required' }],
            },
          },
          { status: 422 },
        ),
      ),
    );
    const err = (await api.post('/thing', {}).catch((e: unknown) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(422);
    expect(err.code).toBe('VALIDATION_FAILED');
    expect(err.fieldErrors()).toEqual({ name: 'Required' });
  });

  it('clears the session on 401', async () => {
    session.set({ token: 'stale', userId: '1' });
    const err = (await api.get('/me').catch((e: unknown) => e)) as ApiError;
    expect(err.status).toBe(401);
  });

  it('describes statuses in business language', () => {
    expect(
      describeError(new ApiError(503, { code: 'ANALYTICS_UNAVAILABLE', message: 'x' }, '')),
    ).toMatch(/temporarily unavailable/);
    expect(describeError(new ApiError(429, { code: 'RATE_LIMITED', message: 'x' }, ''))).toMatch(
      /Too many requests/,
    );
    expect(
      describeError(
        new ApiError(409, { code: 'STEP_NOT_READY', message: 'Save definitions first.' }, ''),
      ),
    ).toBe('Save definitions first.');
    expect(describeError(new ApiError(0, null, ''))).toMatch(/could not be reached/);
  });
});
