import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import type { Role, UserRecord } from '../../shared/api/types';
import { fakeUser, page, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const roles: Role[] = [
  { id: 1, code: 'owner', name_ar: 'المالك' },
  { id: 3, code: 'branch_staff', name_ar: 'موظف الفرع' },
  { id: 6, code: 'agent', name_ar: 'المكتب الخارجي' },
];

const record = (overrides: Partial<UserRecord> = {}): UserRecord => ({
  id: 7,
  name: 'هبة علي',
  username: 'heba',
  active: true,
  role_id: 3,
  branch_id: 1,
  agent_id: null,
  role: roles[1]!,
  branch: { id: 1, name: 'فرع القاهرة' },
  agent: null,
  last_login_at: null,
  ...overrides,
});

const admin = fakeUser({ 'user.manage': 'all', 'masterdata.view': 'all' }, { id: 1 });

const baseRoutes = (users: UserRecord[] = [record()]): MockRoute[] => [
  { path: '/users', body: page(users) },
  { path: '/roles', body: { data: roles } },
  { path: '/branches', body: page([{ id: 1, name: 'فرع القاهرة', country: 'EG', timezone: 'Africa/Cairo', is_main_warehouse: false, phone: null, address: null, active: true }]) },
  { path: '/agents', body: page([{ id: 4, name: 'مكتب الخرطوم', phone: null, country: 'SD', active: true }]) },
];

describe('users screen', () => {
  it('lists users with their role and branch', async () => {
    renderApp('/users', { user: admin, routes: baseRoutes() });

    expect(await screen.findByText('هبة علي')).toBeInTheDocument();
    expect(screen.getByText('heba')).toHaveClass('ltr');
    expect(screen.getByText('موظف الفرع')).toBeInTheDocument();
    expect(screen.getByText('فرع القاهرة')).toBeInTheDocument();
  });

  it('asks for an external office instead of a branch when the role is the office account', async () => {
    const user = userEvent.setup();
    renderApp('/users', { user: admin, routes: baseRoutes() });

    await user.click(await screen.findByRole('button', { name: ar.users.add }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: 'المكتب الخارجي' });

    expect(within(dialog).getByLabelText(new RegExp(ar.users.branch))).toBeInTheDocument();
    await user.selectOptions(within(dialog).getByLabelText(ar.users.role), '6');

    expect(await within(dialog).findByLabelText(new RegExp(ar.users.agent))).toBeInTheDocument();
    expect(within(dialog).queryByLabelText(new RegExp(ar.users.branch))).not.toBeInTheDocument();
  });

  it('creates a branch employee with the branch id and no agent', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/users', {
      user: admin,
      routes: [...baseRoutes(), { method: 'POST', path: '/users', status: 201, body: { data: record() } }],
    });

    await user.click(await screen.findByRole('button', { name: ar.users.add }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: 'موظف الفرع' });
    await user.type(within(dialog).getByLabelText(ar.users.name), 'منى');
    await user.type(within(dialog).getByLabelText(ar.users.username), 'mona');
    await user.type(within(dialog).getByLabelText(ar.users.password), 'long-enough-1');
    await user.selectOptions(within(dialog).getByLabelText(ar.users.role), '3');
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(ar.users.branch)), '1');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    await screen.findByText('هبة علي');
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({
      name: 'منى',
      username: 'mona',
      password: 'long-enough-1',
      role_id: 3,
      branch_id: 1,
      agent_id: null,
    });
  });

  it('shows the one-time temporary password after a reset', async () => {
    const user = userEvent.setup();
    renderApp('/users', {
      user: admin,
      routes: [...baseRoutes(), { method: 'POST', path: '/users/7/reset-password', body: { temporary_password: 'Xk29PqLm81Zt' } }],
    });

    await user.click(await screen.findByRole('button', { name: ar.users.resetPassword }));
    const confirm = screen.getByRole('dialog');
    expect(confirm).toHaveTextContent('هبة علي');
    await user.click(within(confirm).getByRole('button', { name: ar.users.resetPassword }));

    expect(await screen.findByText('Xk29PqLm81Zt')).toBeInTheDocument();
    expect(screen.getByText(ar.users.temporaryPasswordHint)).toBeInTheDocument();
  });

  it('does not offer to deactivate your own account', async () => {
    renderApp('/users', { user: admin, routes: baseRoutes([record({ id: 1, name: 'أنا' }), record({ id: 2, name: 'غيري' })]) });

    await screen.findByText('غيري');
    const ownRow = screen.getByText('أنا').closest('tr')!;
    const otherRow = screen.getByText('غيري').closest('tr')!;

    expect(within(ownRow).queryByRole('button', { name: ar.common.deactivate })).not.toBeInTheDocument();
    expect(within(otherRow).getByRole('button', { name: ar.common.deactivate })).toBeInTheDocument();
  });
});
