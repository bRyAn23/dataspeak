// FE-PG-007: an analysis whose report job died offers "Build the report again" and completes without re-upload.
import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderApp } from './render';
import { seedInterruptedReport, state } from './msw/handlers';
import { session } from '@/lib/session';

describe('interrupted report job', () => {
  it('explains the failure, rebuilds the report and opens it', async () => {
    const user = userEvent.setup();
    state.token = 'tok-123';
    session.set({ token: 'tok-123', userId: '1' });
    seedInterruptedReport('f1', 'Retail 2025');

    renderApp(['/analyses/f1/analyze']);
    expect(await screen.findByRole('alert')).toHaveTextContent(/stopped before it finished/);
    expect(screen.queryByRole('button', { name: 'Retry analysis' })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Build the report again' }));
    expect(
      await screen.findByText('All done. Your report is ready.', {}, { timeout: 8000 }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Open the report/ }));
    expect(
      await screen.findByRole('heading', { name: 'Executive summary' }, { timeout: 5000 }),
    ).toBeInTheDocument();
  }, 20000);
});
