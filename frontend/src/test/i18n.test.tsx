// FEAT-I18N-003: the interface switches language everywhere and tells the API which language to use.
import { describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderApp } from './render';
import { seedAnalysis, state } from './msw/handlers';
import { session } from '@/lib/session';
import { activateLocale, t } from '@/i18n';

describe('interface languages', () => {
  it('translates with interpolation and falls back to English', () => {
    activateLocale('th');
    expect(t('Analyses')).toBe('การวิเคราะห์');
    expect(t('Page {current} of {last}', { current: 2, last: 5 })).toBe('หน้า 2 จาก 5');
    expect(t('A string nobody translated')).toBe('A string nobody translated');
    activateLocale('en');
  });

  it('switches the whole interface from the sidebar', async () => {
    const user = userEvent.setup();
    state.token = 'tok-123';
    session.set({ token: 'tok-123', userId: '1' });
    seedAnalysis('l1', 'Lang test', 'COMPLETED');
    renderApp(['/analyses']);

    expect(await screen.findByRole('heading', { name: 'Analyses' })).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText('Language'), 'zh');
    expect(await screen.findByRole('heading', { name: '分析' })).toBeInTheDocument();
    expect(
      within(screen.getByRole('navigation', { name: '主导航' })).getByText('新建分析'),
    ).toBeInTheDocument();
    expect(document.documentElement.lang).toBe('zh');

    await user.selectOptions(screen.getByLabelText('语言'), 'en');
    expect(await screen.findByRole('heading', { name: 'Analyses' })).toBeInTheDocument();
  });
});
