// FE-PG-005/006: profiling suggests English names and meanings; Define shows them and can ask again.
import { describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderApp } from './render';
import { seedAnalysis, state } from './msw/handlers';
import { session } from '@/lib/session';

describe('suggested definitions (FEAT-DEF-003)', () => {
  it('shows suggested meanings on the profile and prefills the Define step', async () => {
    state.token = 'tok-123';
    session.set({ token: 'tok-123', userId: '1' });
    seedAnalysis('p1', 'Thai sales', 'PROFILED');

    renderApp(['/analyses/p1/profile']);
    const table = await screen.findByRole('table', { name: 'Column profile' });
    expect(within(table).getByText('Sales (THB)')).toBeInTheDocument();
    expect(within(table).getByText('Region')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent(/suggested by the AI model \(qwen3:8b\)/);
  });

  it('prefills Define from the profile, applies "Suggest again" to untouched rows only', async () => {
    const user = userEvent.setup();
    state.token = 'tok-123';
    session.set({ token: 'tok-123', userId: '1' });
    seedAnalysis('p2', 'Thai sales', 'PROFILED');

    renderApp(['/analyses/p2/define']);
    const sales = await screen.findByLabelText('Business name for ยอดขาย_thb');
    expect(sales).toHaveValue('Sales (THB)');
    expect(screen.getByLabelText('Business name for ဒေသ')).toHaveValue('Region');
    expect(screen.getByRole('status')).toHaveTextContent(/suggested when the file was profiled/);
    expect(screen.getAllByText('Suggested, check it')).toHaveLength(3);

    // The user renames one column; a new suggestion run must not overwrite it but updates the others
    await user.clear(sales);
    await user.type(sales, 'Net Sales');
    await user.click(screen.getByRole('button', { name: 'Suggest again' }));
    await waitFor(() =>
      expect(screen.getByRole('status')).toHaveTextContent(/were suggested by the AI model/),
    );
    expect(sales).toHaveValue('Net Sales');
    expect(screen.getByLabelText('Meaning of ยอดขาย_thb')).toHaveValue('NUMBER');
    expect(screen.getAllByText('AI suggestion, check it')).toHaveLength(1); // ဒေသ came back from the model
  });
});
