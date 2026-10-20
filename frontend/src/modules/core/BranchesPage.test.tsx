import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import type { Branch } from '../../shared/api/types';
import { fakeUser, page, renderApp } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const branch = (overrides: Partial<Branch> = {}): Branch => ({
  id: 1,
  name: 'مخزن فيصل',
  country: 'EG',
  timezone: 'Africa/Cairo',
  is_main_warehouse: true,
  phone: '+20212345678',
  address: null,
  active: true,
  ...overrides,
});

describe('branches screen', () => {
  it('lists branches for any signed-in user but offers no editing without branch.manage', async () => {
    renderApp('/branches', { user: fakeUser({}), routes: [{ path: '/branches', body: page([branch()]) }] });

    expect(await screen.findByText('مخزن فيصل')).toBeInTheDocument();
    expect(screen.getByText(ar.countries.EG)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.branches.add })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.common.edit })).not.toBeInTheDocument();
  });

  it('reports the single-main-warehouse rule next to the checkbox', async () => {
    const user = userEvent.setup();
    renderApp('/branches', {
      user: fakeUser({ 'branch.manage': 'all' }),
      routes: [
        { path: '/branches', body: page([branch()]) },
        {
          method: 'POST',
          path: '/branches',
          status: 422,
          body: { message: 'x', errors: { is_main_warehouse: ['فيه مخزن رئيسي واحد بس'] } },
        },
      ],
    });

    await user.click(await screen.findByRole('button', { name: ar.branches.add }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(ar.branches.name), 'أسوان');
    await user.click(within(dialog).getByLabelText(ar.branches.mainWarehouse));
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    expect(await within(dialog).findByText('فيه مخزن رئيسي واحد بس')).toBeInTheDocument();
  });

  it('deactivates a branch through the API', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/branches', {
      user: fakeUser({ 'branch.manage': 'all' }),
      routes: [
        { path: '/branches', body: page([branch({ id: 9, name: 'أسوان', is_main_warehouse: false })]) },
        { method: 'DELETE', path: '/branches/9', body: { message: 'تم الإيقاف' } },
      ],
    });

    await user.click(await screen.findByRole('button', { name: ar.common.deactivate }));

    await vi.waitFor(() => expect(calls.some((call) => call.method === 'DELETE' && call.path === '/branches/9')).toBe(true));
  });
});
