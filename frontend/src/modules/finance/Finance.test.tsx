import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { baseRoutes, remittance, treasury } from '../../test/fixtures';
import { fakeUser, page, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const accountant = fakeUser({ 'treasury.view': 'all', 'treasury.manage': 'all', 'remittance.confirm': 'all' });
const branchStaff = fakeUser({ 'treasury.view': 'branch', 'remittance.send': 'branch' });

const usd = { id: 2, code: 'USD', name_ar: 'دولار' };
const mainCash = treasury({ id: 8, balance: '0.00', branch_id: 9, branch: { id: 9, name: 'مخزن فيصل' } });
const mainUsd = treasury({ id: 9, currency_id: 2, currency: usd, balance: '10.00', branch_id: 9, branch: { id: 9, name: 'مخزن فيصل' } });

describe('treasuries', () => {
  it('lists every treasury with its balance and lets managers add one', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/treasuries', {
      user: accountant,
      routes: [
        ...baseRoutes,
        { path: '/treasuries', body: page([treasury(), mainUsd]) },
        { path: '/branches', body: page([{ id: 1, name: 'فرع القاهرة', country: 'EG', timezone: 'x', is_main_warehouse: false, phone: null, address: null, active: true }]) },
        { method: 'POST', path: '/treasuries', status: 201, body: { data: treasury() } },
      ],
    });

    expect(await screen.findByText('2,500.00')).toHaveClass('ltr');
    await user.click(screen.getByRole('button', { name: ar.finance.treasuries.add }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: 'فرع القاهرة' });
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(ar.finance.treasuries.branch)), '1');
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(ar.finance.treasuries.currency)), '1');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ branch_id: 1, currency_id: 1, method: 'cash' });
  });

  it('hides the add button from users who cannot manage treasuries', async () => {
    renderApp('/treasuries', { user: branchStaff, routes: [...baseRoutes, { path: '/treasuries', body: page([treasury()]) }] });

    await screen.findByText('2,500.00');
    expect(screen.queryByRole('button', { name: ar.finance.treasuries.add })).not.toBeInTheDocument();
  });

  it('shows the movements of a treasury with signs and colours', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/treasuries', {
      user: accountant,
      routes: [
        ...baseRoutes,
        { path: '/treasuries', body: page([treasury()]) },
        {
          path: '/payments',
          body: page([
            { id: 1, serial_no: '000001', direction: 'in', kind: 'collection', amount: '400.00', currency_id: 1, treasury_id: 3, notes: null, reversal_of_id: null, created_at: '2026-10-18T10:00:00Z' },
            { id: 2, serial_no: '000002', direction: 'out', kind: 'remittance', amount: '150.00', currency_id: 1, treasury_id: 3, notes: null, reversal_of_id: null, created_at: '2026-10-18T11:00:00Z' },
          ]),
        },
      ],
    });

    await user.click(await screen.findByRole('button', { name: ar.finance.treasuries.movements }));
    const dialog = screen.getByRole('dialog');

    const incoming = await within(dialog).findByText('+400.00');
    expect(incoming).toHaveClass('text-ok');
    expect(within(dialog).getByText('−150.00')).toHaveClass('text-danger');
    expect(calls.find((call) => call.path === '/payments')?.query.get('treasury_id')).toBe('3');
  });
});

describe('remittances', () => {
  const list: MockRoute = { path: '/remittances', body: page([remittance(), remittance({ id: 41, serial_no: '000041', status: 'confirmed' })]) };

  it('lets the confirming side confirm a pending remittance', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/remittances', {
      user: accountant,
      routes: [...baseRoutes, list, { method: 'POST', path: '/remittances/40/confirm', body: { data: {} } }],
    });

    await user.click(await screen.findByRole('button', { name: ar.finance.remittances.confirm }));

    await waitFor(() => expect(calls.some((call) => call.path === '/remittances/40/confirm')).toBe(true));
    expect(screen.getAllByRole('button', { name: ar.finance.remittances.confirm })).toHaveLength(1);
  });

  it('needs a reason to reject', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/remittances', {
      user: accountant,
      routes: [...baseRoutes, list, { method: 'POST', path: '/remittances/40/reject', body: { data: {} } }],
    });

    await user.click(await screen.findByRole('button', { name: ar.finance.remittances.reject }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByRole('button', { name: ar.finance.remittances.reject })).toBeDisabled();
    await user.type(within(dialog).getByLabelText(ar.finance.remittances.reason), 'المبلغ غلط');
    await user.click(within(dialog).getByRole('button', { name: ar.finance.remittances.reject }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/remittances/40/reject')?.body).toEqual({ reason: 'المبلغ غلط' });
  });

  it('gives a branch employee no confirm or reject buttons', async () => {
    renderApp('/remittances', { user: branchStaff, routes: [...baseRoutes, list] });

    await screen.findByText('000040');
    expect(screen.queryByRole('button', { name: ar.finance.remittances.confirm })).not.toBeInTheDocument();
  });

  it('sends cash to the warehouse treasury in the same currency', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/remittances', {
      user: branchStaff,
      routes: [
        ...baseRoutes,
        list,
        { path: '/treasuries', body: page([treasury()]) },
        { path: '/remittances/destinations', body: { data: [mainCash, mainUsd] } },
        { method: 'POST', path: '/remittances', status: 201, body: { data: remittance() } },
      ],
    });

    await user.click(await screen.findByRole('button', { name: ar.finance.remittances.new }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: /الرصيد 2,500.00/ });
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(ar.finance.remittances.from)), '3');

    const toOptions = await within(dialog).findAllByRole('option', { name: /مخزن فيصل/ });
    expect(toOptions).toHaveLength(1);
    expect(toOptions[0]).toHaveTextContent('جنيه مصري');

    await user.type(within(dialog).getByLabelText(ar.finance.remittances.amount), '400');
    await user.click(within(dialog).getByRole('button', { name: ar.finance.remittances.send }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/remittances')?.body).toEqual({
      from_treasury_id: 3,
      to_treasury_id: 8,
      amount: '400',
    });
  });

  it('shows the balance rule from the server next to the amount', async () => {
    const user = userEvent.setup();
    renderApp('/remittances', {
      user: branchStaff,
      routes: [
        ...baseRoutes,
        list,
        { path: '/treasuries', body: page([treasury()]) },
        { path: '/remittances/destinations', body: { data: [mainCash] } },
        { method: 'POST', path: '/remittances', status: 422, body: { message: 'x', errors: { amount: ['المبلغ أكبر من رصيد الخزنة'] } } },
      ],
    });

    await user.click(await screen.findByRole('button', { name: ar.finance.remittances.new }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: /الرصيد/ });
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(ar.finance.remittances.from)), '3');
    await user.type(within(dialog).getByLabelText(ar.finance.remittances.amount), '99999');
    await user.click(within(dialog).getByRole('button', { name: ar.finance.remittances.send }));

    expect(await within(dialog).findByText('المبلغ أكبر من رصيد الخزنة')).toBeInTheDocument();
  });
});
