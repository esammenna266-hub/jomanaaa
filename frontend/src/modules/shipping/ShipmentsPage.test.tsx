import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { baseRoutes, summary } from '../../test/fixtures';
import { fakeUser, page, renderApp } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const staff = fakeUser({ 'shipment.view': 'branch', 'shipment.create': 'branch' });

describe('shipments list', () => {
  it('shows each shipment with its status, net price and a link to the file', async () => {
    renderApp('/shipments', {
      user: staff,
      routes: [...baseRoutes, { path: '/shipments', body: page([summary({ status: 'pending_approval' })]) }],
    });

    const serial = await screen.findByRole('link', { name: '000010' });
    expect(serial).toHaveAttribute('href', '/shipments/10');
    expect(screen.getByText('سعد محمود')).toBeInTheDocument();
    expect(within(screen.getByRole('table')).getByText(ar.shipments.status.pending_approval)).toBeInTheDocument();
    expect(await screen.findByText('900.00')).toBeInTheDocument();
  });

  it('filters by status and by search text', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments', { user: staff, routes: [...baseRoutes, { path: '/shipments', body: page([summary()]) }] });

    await screen.findByRole('link', { name: '000010' });
    await user.selectOptions(screen.getByRole('combobox', { name: ar.common.status }), 'approved');
    await user.type(screen.getByRole('searchbox'), 'ABC');

    await vi.waitFor(() => {
      const last = calls.filter((call) => call.path === '/shipments').at(-1);
      expect(last?.query.get('status')).toBe('approved');
      expect(last?.query.get('q')).toBe('ABC');
    });
  });

  it('offers the new-shipment button only to users who can create shipments', async () => {
    renderApp('/shipments', {
      user: fakeUser({ 'shipment.view': 'all' }),
      routes: [...baseRoutes, { path: '/shipments', body: page([]) }],
    });

    expect(await screen.findByText(ar.common.empty)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.shipments.new })).not.toBeInTheDocument();
  });

  it('keeps the new-shipment screen away from users who cannot create shipments', async () => {
    renderApp('/shipments/new', { user: fakeUser({ 'shipment.view': 'all' }), routes: baseRoutes });

    expect(await screen.findByText(ar.home.welcome('سارة'))).toBeInTheDocument();
  });
});
