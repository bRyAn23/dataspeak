// Integration: login -> create -> upload -> profile -> define -> analyze (polling) -> report -> open claim.
import { describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderApp } from './render';
import { SENTINEL } from './msw/handlers';

describe('end-to-end workflow against the Laravel contract (MSW)', () => {
  it('walks the five steps and opens traceability', async () => {
    const user = userEvent.setup();
    renderApp(['/login']);

    // Login (FE-PG-001)
    await user.type(await screen.findByLabelText('Email address'), 'zywint@gmail.com');
    await user.type(screen.getByLabelText('Password'), 'secret-pass-1');
    await user.click(screen.getByRole('button', { name: 'Log in' }));
    expect(await screen.findByRole('heading', { name: 'Analyses' })).toBeInTheDocument();
    expect(await screen.findByText('No analyses yet')).toBeInTheDocument();

    // New analysis (FE-PG-004): name auto-fills from the file
    await user.click(
      within(screen.getByRole('navigation', { name: 'Primary' })).getByRole('link', {
        name: /New analysis/,
      }),
    );
    const file = new File([`region,amount\nNorth,${SENTINEL}\n`], 'retail_sales_2025.csv', {
      type: 'text/csv',
    });
    await user.upload(screen.getByTestId('csv-input'), file);
    expect(screen.getByLabelText('Analysis name')).toHaveValue('Retail Sales 2025');
    await user.click(screen.getByRole('button', { name: 'Create and upload' }));
    expect(
      await screen.findByText('File checked successfully', {}, { timeout: 5000 }),
    ).toBeInTheDocument();
    expect(screen.getByText(/240 rows/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Continue to profile/ }));

    // Profile (FE-PG-005): Thai and Myanmar headers intact, counts only
    expect(await screen.findByRole('heading', { name: 'Columns' })).toBeInTheDocument();
    expect(screen.getAllByText('ยอดขาย_thb').length).toBeGreaterThan(0);
    expect(screen.getAllByText('ဒေသ').length).toBeGreaterThan(0);
    expect(screen.getAllByText(/14\.6%/).length).toBeGreaterThan(0);
    await user.click(screen.getByRole('button', { name: /Continue to definitions/ }));

    // Define (FE-PG-006): currency without unit is rejected client-side, then saved
    const table = await screen.findByRole('table', { name: 'Field definitions' });
    const typeSelect = within(table).getByLabelText('Meaning of ยอดขาย_thb');
    await user.selectOptions(typeSelect, 'CURRENCY');
    await user.click(screen.getByRole('button', { name: /Save and continue/ }));
    expect(await screen.findByText(/Currency needs a unit/)).toBeInTheDocument();
    await user.type(within(table).getByLabelText('Unit for ยอดขาย_thb'), 'THB');
    await user.click(screen.getByRole('button', { name: /Save and continue/ }));

    // Analyze (FE-PG-007): the run stops once charts are recommended; the user reviews them (FEAT-VIS-003)
    await user.click(await screen.findByRole('button', { name: /Analyze and recommend charts/ }));
    expect(await screen.findByRole('progressbar')).toBeInTheDocument();
    expect(
      await screen.findByRole(
        'heading',
        { name: 'Choose the charts for your report' },
        { timeout: 8000 },
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Recommended charts/ })).toHaveAttribute(
      'aria-selected',
      'true',
    );
    expect(screen.getByText('Recommended')).toBeInTheDocument();
    await user.click(screen.getByRole('tab', { name: /Custom required charts/ }));
    expect(screen.getByText(/No custom charts yet/)).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText('Chart to add'), 'bar_mean:ev_aaaaaaaaaaa1');
    expect(screen.getByText('Preview with your data')).toBeInTheDocument(); // drawn before it is added
    expect(screen.getByText(/averaged, never totalled/)).toBeInTheDocument(); // FEAT-VIS-006 reason from the definitions
    await user.click(screen.getByRole('button', { name: 'Add chart' }));
    expect(screen.getByText('Added by you')).toBeInTheDocument(); // now a card in the grid, with its data
    expect(
      screen.getByRole('button', { name: 'Remove Average ยอดขาย by ဒေသ' }),
    ).toBeInTheDocument();
    expect(screen.getByText('2 charts will go into the report.')).toBeInTheDocument();
    // FEAT-VIS-004: a colour theme for every chart in the report and the PDF
    expect(screen.getByRole('radio', { name: 'Data Speak green' })).toBeChecked();
    await user.click(screen.getByRole('radio', { name: 'Ocean blue' }));
    expect(screen.getByRole('radio', { name: 'Ocean blue' })).toBeChecked();
    await user.click(screen.getByRole('button', { name: /Write the executive summary/ }));
    expect(
      await screen.findByText('All done. Your report is ready.', {}, { timeout: 8000 }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Open the report/ }));

    // Report (FE-PG-008)
    expect(
      await screen.findByRole('heading', { name: 'Executive summary' }, { timeout: 5000 }),
    ).toBeInTheDocument();
    // The sentence appears in the executive summary paragraph and again under its chart.
    expect(
      screen.getAllByText(/North accounts for 54.3% of ยอดขาย across ဒေသ\./).length,
    ).toBeGreaterThanOrEqual(2);
    expect(screen.queryByText(/Revenue was 14 million/)).not.toBeInTheDocument(); // rejected claims never render
    expect(screen.getByText('292.4 THB')).toBeInTheDocument();
    expect(screen.getByText('What this chart shows')).toBeInTheDocument();
    // Export PDF (FE-RPT-007): the browser downloads the server-rendered file
    const createUrl = vi.fn(() => 'blob:fake');
    const revokeUrl = vi.fn();
    Object.assign(URL, { createObjectURL: createUrl, revokeObjectURL: revokeUrl });
    const clicks: string[] = [];
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      clicks.push(this.download);
    });
    await user.click(screen.getByRole('button', { name: 'Export PDF' }));
    await waitFor(() => expect(clicks).toEqual(['DataSpeak-Retail Sales 2025-20261004.pdf']));
    expect(createUrl).toHaveBeenCalledTimes(1);
    clickSpy.mockRestore();

    // Traceability (FE-PG-009): business wording first, technical identifiers collapsed
    await user.click(screen.getAllByRole('link', { name: /How we know/ })[0]);
    const dialog = await screen.findByRole('dialog', { name: 'How we know' });
    expect(within(dialog).getByText('Share held by the leading group')).toBeInTheDocument();
    expect(
      within(dialog).getByText(/Compared each group total with the overall total/),
    ).toBeInTheDocument();
    expect(within(dialog).getByText('Checked against the calculated results')).toBeInTheDocument();
    const details = within(dialog)
      .getByText('Technical details')
      .closest('details') as HTMLDetailsElement;
    expect(details.open).toBe(false);
    expect(within(details).getByText(/prompt version exec-interp/)).toBeInTheDocument();
    expect(screen.queryByText(/qwen3/)).not.toBeInTheDocument(); // model names never shown
    expect(within(details).getByText(/\{ev_aaaaaaaaaaa1\.group\}/)).toBeInTheDocument();
  }, 30000);

  it('shows the lifecycle conflict explanation on 409 and redirects when a step is not allowed', async () => {
    const { seedAnalysis, state } = await import('./msw/handlers');
    state.token = 'tok-123';
    const { session } = await import('@/lib/session');
    session.set({ token: 'tok-123', userId: '1' });
    seedAnalysis('s1', 'Seeded', 'VALIDATED');
    renderApp(['/analyses/s1/report']);
    // Report step is not allowed for VALIDATED: redirected to the profile step
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Seeded' })).toBeInTheDocument(),
    );
    expect(await screen.findByRole('heading', { name: 'Columns' })).toBeInTheDocument();
  });
});
