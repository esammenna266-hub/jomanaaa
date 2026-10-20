import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { baseRoutes, treasury } from '../../test/fixtures';
import { fakeUser, page, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const owner = fakeUser({ 'agent_price.manage': 'all', 'masterdata.view': 'all', 'masterdata.manage': 'all', 'payment.collect': 'all' });
const viewer = fakeUser({ 'agent_price.manage': 'all', 'masterdata.view': 'all' });

const office = { id: 4, name: 'مكتب النور', phone: '+249912345678', country: 'SD', active: true };
const price = { id: 9, agent_id: 4, service: 'bus', route: 'القاهرة - الخرطوم', price: '1800.00', currency_id: 1, active: true };
const account = {
  agent: office,
  balances: [{ currency_id: 1, due: '3000.00', paid: '1000.00', remaining: '2000.00' }],
  settlements: [{ id: 70, serial_no: 'P000070', direction: 'in', kind: 'agent_settlement', amount: '1000.00', currency_id: 1, treasury_id: 8, notes: 'دفعة أولى', reversal_of_id: null, created_at: '2026-10-18T09:00:00Z' }],
};

const fileRoutes = (extra: MockRoute[] = []): MockRoute[] => [
  ...baseRoutes,
  { path: '/agents/4', body: { data: office } },
  { path: '/agents/4/account', body: { data: account } },
  { path: '/agents/4/prices', body: { data: [price] } },
  { path: '/treasuries', body: page([treasury({ id: 8, currency_id: 1 }), treasury({ id: 9, currency_id: 2 })]) },
  ...extra,
];

describe('agents list', () => {
  it('lists offices, links to their file and adds a new one', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/agents', {
      user: owner,
      routes: [{ path: '/agents', body: page([office]) }, { method: 'POST', path: '/agents', status: 201, body: { data: office } }],
    });

    expect(await screen.findByRole('link', { name: 'مكتب النور' })).toHaveAttribute('href', '/agents/4');

    await user.click(screen.getByRole('button', { name: ar.agents.add }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.agents.name}`)), 'مكتب الأمل');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/agents')?.body).toEqual({ name: 'مكتب الأمل', country: 'SD', phone: null });
  });

  it('hides the add button from those who cannot manage master data', async () => {
    renderApp('/agents', { user: viewer, routes: [{ path: '/agents', body: page([office]) }] });

    await screen.findByRole('link', { name: 'مكتب النور' });
    expect(screen.queryByRole('button', { name: ar.agents.add })).not.toBeInTheDocument();
  });
});

describe('agent file', () => {
  it('shows what the office owes, what it paid and its price list', async () => {
    renderApp('/agents/4', { user: owner, routes: fileRoutes() });

    expect(await screen.findByRole('heading', { name: 'مكتب النور' })).toBeInTheDocument();
    const balance = await screen.findByText('2,000.00');
    expect(within(balance.closest('tr')!).getByText('3,000.00')).toBeInTheDocument();
    expect(screen.getByText('P000070')).toBeInTheDocument();
    expect(screen.getByText('القاهرة - الخرطوم')).toBeInTheDocument();
    expect(screen.getByText(ar.agents.service.bus)).toBeInTheDocument();
  });

  it('records a payment into a treasury and shows what is left in its currency', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/agents/4', {
      user: owner,
      routes: fileRoutes([{ method: 'POST', path: '/agents/4/settlements', status: 201, body: { data: { id: 71 } } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.agents.recordPayment }));
    const dialog = screen.getByRole('dialog');
    const submit = within(dialog).getByRole('button', { name: ar.agents.recordPayment });
    expect(submit).toBeDisabled();

    await screen.findAllByRole('option', { name: /نقدي/ });
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(`^${ar.agents.treasury}`)), '8');
    expect(within(dialog).getByText(/المتبقي على المكتب 2,000.00/)).toBeInTheDocument();
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.agents.amount}`)), '500');
    await user.click(submit);

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/agents/4/settlements')?.body).toEqual({ treasury_id: 8, amount: '500' });
  });

  it('shows the server problem when the payment is larger than the balance', async () => {
    const user = userEvent.setup();
    renderApp('/agents/4', {
      user: owner,
      routes: fileRoutes([{ method: 'POST', path: '/agents/4/settlements', status: 422, body: { message: 'x', errors: { amount: ['المبلغ أكبر من المتبقي على المكتب في العملة دي'] } } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.agents.recordPayment }));
    const dialog = screen.getByRole('dialog');
    await screen.findAllByRole('option', { name: /نقدي/ });
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(`^${ar.agents.treasury}`)), '8');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.agents.amount}`)), '999999');
    await user.click(within(dialog).getByRole('button', { name: ar.agents.recordPayment }));

    expect(await within(dialog).findByText('المبلغ أكبر من المتبقي على المكتب في العملة دي')).toBeInTheDocument();
  });

  it('adds a price for a service and route', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/agents/4', {
      user: owner,
      routes: fileRoutes([{ method: 'POST', path: '/agents/4/prices', status: 201, body: { data: price } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.agents.addPrice }));
    const dialog = screen.getByRole('dialog');
    await user.selectOptions(within(dialog).getByLabelText(ar.agents.serviceLabel), 'flight');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.agents.route}`)), 'القاهرة - جوبا');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.agents.price}`)), '220');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/agents/4/prices')?.body).toEqual({
      service: 'flight',
      route: 'القاهرة - جوبا',
      price: '220',
      currency_id: 1,
    });
  });

  it('switches a price off', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/agents/4', {
      user: owner,
      routes: fileRoutes([{ method: 'DELETE', path: '/agent-prices/9', body: { message: 'تم الإيقاف' } }]),
    });

    await user.click(await screen.findByRole('button', { name: `${ar.common.deactivate} القاهرة - الخرطوم` }));

    await waitFor(() => expect(calls.some((call) => call.method === 'DELETE' && call.path === '/agent-prices/9')).toBe(true));
  });

  it('hides payment recording from those who cannot collect', async () => {
    renderApp('/agents/4', { user: viewer, routes: fileRoutes() });

    await screen.findByRole('heading', { name: 'مكتب النور' });
    expect(screen.queryByRole('button', { name: ar.agents.recordPayment })).not.toBeInTheDocument();
  });
});

describe('office portal', () => {
  const officeUser = fakeUser(
    { 'booking.view': 'own', 'booking.create': 'own', 'shipment.view': 'own', 'shipment.create': 'own', 'trip.view': 'all' },
    { branch: null, agent: { id: 4, name: 'مكتب النور' } },
  );

  it('gives an office its own account page and menu entry, without the branches screen', async () => {
    const user = userEvent.setup();
    renderApp('/', { user: officeUser, routes: [...baseRoutes, { path: '/agents/4/account', body: { data: account } }] });

    const menu = await screen.findByRole('navigation', { name: ar.nav.menu });
    expect(within(menu).queryByRole('link', { name: ar.nav.branches })).not.toBeInTheDocument();
    expect(within(menu).queryByRole('link', { name: ar.nav.agents })).not.toBeInTheDocument();

    await user.click(within(menu).getByRole('link', { name: ar.nav.myAccount }));
    expect(await screen.findByRole('heading', { name: ar.agents.myAccount })).toBeInTheDocument();
    const remaining = await screen.findByText('2,000.00');
    expect(within(remaining.closest('tr')!).getByText('3,000.00')).toBeInTheDocument();
    expect(screen.getByText('P000070')).toBeInTheDocument();
    // The office sees what it pays, not how the price list is managed.
    expect(screen.queryByText(ar.agents.pricesTitle)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.agents.recordPayment })).not.toBeInTheDocument();
  });

  it('keeps company staff away from the office account menu entry', async () => {
    renderApp('/', { user: owner, routes: baseRoutes });

    const menu = await screen.findByRole('navigation', { name: ar.nav.menu });
    expect(within(menu).queryByRole('link', { name: ar.nav.myAccount })).not.toBeInTheDocument();
    expect(within(menu).getByRole('link', { name: ar.nav.branches })).toBeInTheDocument();
  });

  it('tells a non-office account that the page is for offices', async () => {
    renderApp('/my-account', { user: owner, routes: baseRoutes });

    expect(await screen.findByText(ar.agents.officesOnly)).toBeInTheDocument();
  });
});
