import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { tokenStore } from '../../shared/api/client';
import { fakeUser, renderApp } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

describe('login', () => {
  it('signs in, keeps the token and lands on the home screen', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/login', {
      user: null,
      routes: [{ method: 'POST', path: '/auth/login', body: { token: 'new-token', user: fakeUser({}, { name: 'المالك' }) } }],
    });

    await user.type(await screen.findByLabelText(ar.auth.username), ' boss ');
    await user.type(screen.getByLabelText(ar.auth.password), 'secret-pass');
    await user.click(screen.getByRole('button', { name: ar.auth.signIn }));

    expect(await screen.findByText(ar.home.welcome('المالك'))).toBeInTheDocument();
    expect(tokenStore.get()).toBe('new-token');
    expect(calls.find((call) => call.path === '/auth/login')?.body).toEqual({ username: 'boss', password: 'secret-pass' });
  });

  it('shows the server message when the credentials are wrong', async () => {
    const user = userEvent.setup();
    renderApp('/login', {
      user: null,
      routes: [
        {
          method: 'POST',
          path: '/auth/login',
          status: 422,
          body: { message: 'x', errors: { username: ['اسم المستخدم أو كلمة المرور غير صحيحة'] } },
        },
      ],
    });

    await user.type(await screen.findByLabelText(ar.auth.username), 'boss');
    await user.type(screen.getByLabelText(ar.auth.password), 'wrong');
    await user.click(screen.getByRole('button', { name: ar.auth.signIn }));

    expect(await screen.findByText('اسم المستخدم أو كلمة المرور غير صحيحة')).toBeInTheDocument();
    expect(tokenStore.get()).toBeNull();
  });
});

describe('route guards', () => {
  it('sends visitors without a session to the login page', async () => {
    renderApp('/customers', { user: null });

    expect(await screen.findByRole('heading', { name: ar.auth.loginTitle })).toBeInTheDocument();
  });

  it('forces a temporary password to be replaced before anything else', async () => {
    renderApp('/customers', { user: fakeUser({ 'customer.view': 'all' }, { must_change_password: true }) });

    expect(await screen.findByRole('heading', { name: ar.auth.changePasswordTitle })).toBeInTheDocument();
  });

  it('hides screens the user has no permission for, in the menu and by URL', async () => {
    renderApp('/users', { user: fakeUser({ 'customer.view': 'branch' }) });

    expect(await screen.findByText(ar.home.welcome('سارة'))).toBeInTheDocument();
    const menu = screen.getByRole('navigation', { name: ar.nav.menu });
    expect(menu).toHaveTextContent(ar.nav.customers);
    expect(menu).not.toHaveTextContent(ar.nav.users);
  });

  it('returns to the login page when the session expires mid-use', async () => {
    renderApp('/customers', {
      user: fakeUser({ 'customer.view': 'all' }),
      routes: [{ path: '/customers', status: 401, body: { message: 'Unauthenticated.' } }],
    });

    expect(await screen.findByRole('heading', { name: ar.auth.loginTitle })).toBeInTheDocument();
    await waitFor(() => expect(tokenStore.get()).toBeNull());
  });
});
