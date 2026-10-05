// FEAT-I18N-003: changing the interface language translates the verified summary (no regeneration).
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderApp } from './render';
import { seedAnalysis, state } from './msw/handlers';
import { session } from '@/lib/session';

describe('report follows the interface language', () => {
  it('translates the existing statements when the language changes, without regenerating', async () => {
    const user = userEvent.setup();
    state.token = 'tok-123';
    session.set({ token: 'tok-123', userId: '1' });
    const a = seedAnalysis('r1', 'Lang report', 'COMPLETED');
    a.has_report = true;

    renderApp(['/analyses/r1/report']);
    expect(await screen.findByRole('heading', { name: 'Executive summary' })).toBeInTheDocument();
    expect(state.translatedTo.get('r1')).toBeUndefined(); // same language: nothing to translate

    await user.selectOptions(screen.getByLabelText('Language'), 'th');
    await waitFor(() => expect(state.translatedTo.get('r1')?.has('th')).toBe(true), {
      timeout: 5000,
    });
    expect(state.reportLanguage.get('r1')).toBeUndefined(); // the report was NOT regenerated
    expect(await screen.findByRole('heading', { name: 'บทสรุปผู้บริหาร' })).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByText(/กำลังแปลบทสรุป/)).not.toBeInTheDocument(), {
      timeout: 5000,
    });
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument(); // the report stayed on screen
  }, 20000);
});
