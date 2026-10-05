import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import { axe } from 'vitest-axe';
import { renderApp } from './render';
import { seedAnalysis, state } from './msw/handlers';
import { session } from '@/lib/session';

describe('accessibility (NFR-ACC-001)', () => {
  it('login page has no detectable violations', async () => {
    const { container } = renderApp(['/login']);
    await screen.findByLabelText('Email address');
    const results = await axe(container);
    expect(results.violations.map((v) => `${v.id}: ${v.nodes.length}`)).toEqual([]);
  });

  it('dashboard with rows has no detectable violations', async () => {
    state.token = 'tok-123';
    session.set({ token: 'tok-123', userId: '1' });
    seedAnalysis('d1', 'Quarterly sales', 'PROFILED');
    seedAnalysis('d2', 'Done one', 'COMPLETED');
    const { container } = renderApp(['/analyses']);
    await screen.findByText('Quarterly sales');
    const results = await axe(container);
    expect(results.violations.map((v) => `${v.id}: ${v.nodes.length}`)).toEqual([]);
  });
});
