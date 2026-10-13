// FE-PG-002 + FE-PG-010: sign-up leads to the confirmation screen; the app opens once /me says verified.
import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderApp } from './render';
import { markVerified, setVerificationRequired } from './msw/handlers';

describe('sign-up with email verification', () => {
  it('registers, waits on the verify page, resends, and continues after confirmation', async () => {
    const user = userEvent.setup();
    renderApp(['/register']);

    await user.type(await screen.findByLabelText('Full name'), 'Nu Nu');
    await user.type(screen.getByLabelText('Email address'), 'nunu@example.com');
    await user.type(screen.getByLabelText('Password'), 'secret-pass-1');
    await user.type(screen.getByLabelText('Confirm password'), 'secret-pass-1');
    await user.click(screen.getByRole('button', { name: 'Create account' }));

    expect(await screen.findByRole('heading', { name: 'Confirm your email' })).toBeInTheDocument();
    expect(screen.getByText('nunu@example.com')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Send the email again' }));
    expect(await screen.findByRole('status')).toHaveTextContent(
      'We sent a new confirmation email to nunu@example.com.',
    );

    // Not confirmed yet: stays on the page with an explanation
    await user.click(screen.getByRole('button', { name: 'I have confirmed my email' }));
    expect(await screen.findByText(/Not confirmed yet/)).toBeInTheDocument();

    // The link was clicked in the mail client: /me now reports verified and the app opens
    markVerified();
    await user.click(screen.getByRole('button', { name: 'I have confirmed my email' }));
    expect(await screen.findByRole('heading', { name: 'Analyses' })).toBeInTheDocument();
  });

  it('opens the app straight after sign-up when the server does not require verification', async () => {
    setVerificationRequired(false);
    const user = userEvent.setup();
    renderApp(['/register']);

    await user.type(await screen.findByLabelText('Full name'), 'No Mail');
    await user.type(screen.getByLabelText('Email address'), 'nomail@example.com');
    await user.type(screen.getByLabelText('Password'), 'secret-pass-1');
    await user.type(screen.getByLabelText('Confirm password'), 'secret-pass-1');
    await user.click(screen.getByRole('button', { name: 'Create account' }));

    expect(await screen.findByRole('heading', { name: 'Analyses' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Confirm your email' })).not.toBeInTheDocument();
  });

  it('shows the confirmation banner on the login page after the API redirect', async () => {
    renderApp(['/login?verified=1']);
    expect(await screen.findByRole('status')).toHaveTextContent('Your email is confirmed.');
  });
});
