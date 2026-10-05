import { describe, expect, it } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { HttpResponse, http } from 'msw';
import type { ReactNode } from 'react';
import { createQueryClient } from '@/config/queryClient';
import { server } from '@/test/msw/server';
import { BASE } from '@/test/msw/handlers';
import { session } from '@/lib/session';
import { isStopped, useAnalysisStatus } from './useAnalysisStatus';

function wrapper() {
  const client = createQueryClient();
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useAnalysisStatus (FE-HOOK-001)', () => {
  it('stops on terminal states and on the step stop state', () => {
    expect(isStopped('COMPLETED')).toBe(true);
    expect(isStopped('FAILED')).toBe(true);
    expect(isStopped('ANALYZING')).toBe(false);
    expect(isStopped('ANALYZED', 'ANALYZED')).toBe(true);
    expect(isStopped('ANALYZED')).toBe(false);
  });

  it('polls while processing and stops after a terminal response, and on unmount', async () => {
    session.set({ token: 'tok-123', userId: '1' });
    let calls = 0;
    server.use(
      http.get(`${BASE}/analyses/p1/status`, () => {
        calls++;
        const done = calls >= 3;
        return HttpResponse.json({
          data: {
            analysis_id: 'p1',
            status: done ? 'COMPLETED' : 'REPORTING',
            stage: 'REPORTING',
            progress: done ? 100 : 50,
            job: null,
            is_terminal: done,
            last_error: null,
            poll_seconds: 0.1,
          },
        });
      }),
    );
    const { result, unmount } = renderHook(() => useAnalysisStatus('p1'), { wrapper: wrapper() });
    await waitFor(() => expect(result.current.data?.status).toBe('REPORTING'));
    await waitFor(() => expect(result.current.data?.status).toBe('COMPLETED'), { timeout: 3000 });
    const callsAtStop = calls;
    await new Promise((r) => setTimeout(r, 400));
    expect(calls).toBe(callsAtStop); // no polling after a terminal state
    unmount();
    await new Promise((r) => setTimeout(r, 400));
    expect(calls).toBe(callsAtStop); // and none after unmount
  });
});
