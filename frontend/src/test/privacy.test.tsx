// Privacy guards: no FastAPI URL, no raw CSV text in storage or console, token only in sessionStorage.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { env } from '@/config/env';
import { renderApp } from './render';
import { SENTINEL } from './msw/handlers';

describe('privacy baseline', () => {
  afterEach(() => vi.restoreAllMocks());

  it('knows only the Laravel API base URL', () => {
    expect(env.apiBaseUrl).toMatch(/\/api\/v1$/);
    expect(JSON.stringify(import.meta.env)).not.toMatch(/8001|fastapi|analytics:8000/i);
  });

  it('never writes raw CSV content to storage or the console during upload', async () => {
    const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
    const infoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const user = userEvent.setup();
    renderApp(['/login']);
    await user.type(await screen.findByLabelText('Email address'), 'zywint@gmail.com');
    await user.type(screen.getByLabelText('Password'), 'secret-pass-1');
    await user.click(screen.getByRole('button', { name: 'Log in' }));
    await user.click(
      within(await screen.findByRole('navigation', { name: 'Primary' })).getByRole('link', {
        name: /New analysis/,
      }),
    );
    await user.upload(
      screen.getByTestId('csv-input'),
      new File([`a,b\n1,${SENTINEL}\n`], 'data.csv', { type: 'text/csv' }),
    );
    await user.click(screen.getByRole('button', { name: 'Create and upload' }));
    await screen.findByText('File checked successfully', {}, { timeout: 5000 });

    const consoleText = [logSpy, infoSpy, warnSpy, errSpy]
      .flatMap((s) => s.mock.calls.map((c) => c.map(String).join(' ')))
      .join('\n');
    expect(consoleText).not.toContain(SENTINEL);
    expect(JSON.stringify(localStorage)).not.toContain(SENTINEL);
    expect(JSON.stringify(sessionStorage)).not.toContain(SENTINEL);
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.getItem('dataspeak.session')).toContain('tok-123');
  });
});
