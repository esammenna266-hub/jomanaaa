import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import type { TripDelay, TripExpense } from '../../shared/api/types';
import { baseRoutes, trip, treasury } from '../../test/fixtures';
import { fakeUser, renderApp, type MockRoute } from '../../test/utils';
import { splitEvenly } from './TripExpenses';

afterEach(() => vi.unstubAllGlobals());

const manager = fakeUser({ 'cargo_trip.manage': 'all', 'tracking.update': 'all', 'expense.manage': 'all' });
const trackerOnly = fakeUser({ 'tracking.update': 'all' });

const expense = (overrides: Partial<TripExpense> = {}): TripExpense => ({
  id: 1,
  category: 'وقود',
  point: 'أسوان',
  amount: '100.00',
  currency_id: 1,
  treasury_id: 3,
  voided_at: null,
  created_at: '2026-10-18T10:00:00Z',
  allocations: [
    { id: 1, shipment_id: 10, amount: '50.00', shipment: { id: 10, serial_no: '000010', consignee_name: 'عمر الطيب' } },
    { id: 2, shipment_id: 11, amount: '50.00', shipment: { id: 11, serial_no: '000011', consignee_name: 'ياسر عثمان' } },
  ],
  ...overrides,
});

const delay = (overrides: Partial<TripDelay> = {}): TripDelay => ({
  id: 1, days: 2, cost: '75.00', currency_id: 1, responsible: 'customer', notes: 'المنفذ مقفول', created_at: '2026-10-18T10:00:00Z', ...overrides,
});

const routes = (expenses: TripExpense[] = [], delays: TripDelay[] = [], extra: MockRoute[] = [], stage: 'loaded' | 'cancelled' = 'loaded'): MockRoute[] => [
  ...baseRoutes,
  { path: '/cargo-trips/30', body: { data: trip({ stage }) } },
  { path: '/cargo-trips/30/expenses', body: { data: expenses } },
  { path: '/cargo-trips/30/delays', body: { data: delays } },
  { path: '/expenses/treasuries', body: { data: [treasury()] } },
  ...extra,
];

describe('splitEvenly', () => {
  it('gives the leftover cents to the first shipments, like the server', () => {
    expect(splitEvenly(10000, [1, 2, 3])).toEqual({ 1: 3334, 2: 3333, 3: 3333 });
    expect(splitEvenly(10001, [1, 2])).toEqual({ 1: 5001, 2: 5000 });
    expect(Object.values(splitEvenly(10000, [1, 2, 3])).reduce((a, b) => a + b, 0)).toBe(10000);
  });
});

describe('trip expenses', () => {
  it('lists expenses with how each was split, and totals only the ones still valid', async () => {
    renderApp('/trips/30', {
      user: manager,
      routes: routes([expense(), expense({ id: 2, category: 'رسوم منفذ', amount: '40.00', voided_at: '2026-10-18T12:00:00Z' })]),
    });

    expect(await screen.findByText('وقود')).toBeInTheDocument();
    expect(screen.getAllByText('000010: 50.00 · 000011: 50.00')).toHaveLength(2);
    const voidedRow = screen.getByText('رسوم منفذ').closest('tr')!;
    expect(within(voidedRow).getByText(ar.trips.expenses.voided)).toBeInTheDocument();
    expect(within(voidedRow).queryByRole('button', { name: ar.trips.expenses.void })).not.toBeInTheDocument();
    expect(screen.getByText(ar.trips.expenses.totals, { exact: false }).textContent).toContain('100.00');
  });

  it('is not shown to users without the expense permission', async () => {
    renderApp('/trips/30', { user: trackerOnly, routes: routes() });

    await screen.findByText(ar.trips.file('000030'));
    expect(screen.queryByText(ar.trips.expenses.title)).not.toBeInTheDocument();
    expect(screen.queryByText(ar.trips.delays.title)).not.toBeInTheDocument();
  });

  it('records an expense split evenly and previews each shipment\'s share', async () => {
    const user = userEvent.setup();
    let saved = false;
    const calls = renderApp('/trips/30', {
      user: manager,
      routes: routes([], [], [
        { path: '/cargo-trips/30/expenses', body: () => ({ data: saved ? [expense()] : [] }) },
        { method: 'POST', path: '/cargo-trips/30/expenses', status: 201, body: () => { saved = true; return { data: expense() }; } },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.expenses.add }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: /الرصيد 2,500.00/ });
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.expenses.category}`)), 'وقود');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.expenses.amount}`)), '100.01');

    expect(within(dialog).getByText('50.01')).toBeInTheDocument();
    expect(within(dialog).getByText('50.00')).toBeInTheDocument();

    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/cargo-trips/30/expenses')?.body).toEqual({
      category: 'وقود',
      treasury_id: 3,
      amount: '100.01',
    });
    expect(await screen.findByText('000010: 50.00 · 000011: 50.00')).toBeInTheDocument();
  });

  it('with a manual split, waits until the shares add up to the expense', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/trips/30', {
      user: manager,
      routes: routes([], [], [{ method: 'POST', path: '/cargo-trips/30/expenses', status: 201, body: { data: expense() } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.expenses.add }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: /الرصيد/ });
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.expenses.category}`)), 'عمولة');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.expenses.amount}`)), '100');
    await user.selectOptions(within(dialog).getByLabelText(ar.trips.expenses.split), 'manual');

    const save = within(dialog).getByRole('button', { name: ar.common.save });
    await user.type(within(dialog).getByLabelText(`${ar.trips.expenses.share} 000010`), '60');
    await user.type(within(dialog).getByLabelText(`${ar.trips.expenses.share} 000011`), '30');
    expect(save).toBeDisabled();
    expect(within(dialog).getByText(ar.trips.expenses.remainder('10.00'))).toBeInTheDocument();

    await user.clear(within(dialog).getByLabelText(`${ar.trips.expenses.share} 000011`));
    await user.type(within(dialog).getByLabelText(`${ar.trips.expenses.share} 000011`), '40');
    expect(within(dialog).getByText(ar.trips.expenses.balanced)).toBeInTheDocument();
    expect(save).toBeEnabled();
    await user.click(save);

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/cargo-trips/30/expenses')?.body).toEqual({
      category: 'عمولة',
      treasury_id: 3,
      amount: '100',
      allocations: [{ shipment_id: 10, amount: '60' }, { shipment_id: 11, amount: '40' }],
    });
  });

  it('shows a refusal from the server inside the form', async () => {
    const user = userEvent.setup();
    renderApp('/trips/30', {
      user: manager,
      routes: routes([], [], [
        { method: 'POST', path: '/cargo-trips/30/expenses', status: 422, body: { message: 'x', errors: { treasury_id: ['الخزنة موقوفة'] } } },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.expenses.add }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: /الرصيد/ });
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.expenses.category}`)), 'وقود');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.expenses.amount}`)), '10');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    expect(await within(dialog).findByText('الخزنة موقوفة')).toBeInTheDocument();
  });

  it('voids an expense with a reason', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/trips/30', {
      user: manager,
      routes: routes([expense()], [], [{ method: 'POST', path: '/expenses/1/void', body: { data: {} } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.expenses.void }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(ar.trips.expenses.voidHint)).toBeInTheDocument();
    const confirm = within(dialog).getByRole('button', { name: ar.trips.expenses.void });
    expect(confirm).toBeDisabled();
    await user.type(within(dialog).getByLabelText(ar.trips.expenses.voidReason), 'اتسجل على رحلة غلط');
    await user.click(confirm);

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/expenses/1/void')?.body).toEqual({ reason: 'اتسجل على رحلة غلط' });
  });

  it('offers no new expense or delay on a cancelled trip', async () => {
    renderApp('/trips/30', { user: manager, routes: routes([], [], [], 'cancelled') });

    await screen.findByText(ar.trips.expenses.title);
    expect(screen.queryByRole('button', { name: ar.trips.expenses.add })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.trips.delays.add })).not.toBeInTheDocument();
  });
});

describe('trip delays', () => {
  it('lists delays with their cost and who is responsible', async () => {
    renderApp('/trips/30', { user: manager, routes: routes([], [delay(), delay({ id: 2, cost: '0.00', currency_id: null, responsible: 'company', notes: null })]) });

    expect(await screen.findByText('المنفذ مقفول')).toBeInTheDocument();
    const row = screen.getByText('المنفذ مقفول').closest('tr')!;
    expect(within(row).getByText(ar.trips.delays.responsibleOptions.customer)).toBeInTheDocument();
    expect(within(row).getByText('75.00')).toBeInTheDocument();
  });

  it('records a delay with a cost, defaulting the currency', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/trips/30', {
      user: manager,
      routes: routes([], [], [{ method: 'POST', path: '/cargo-trips/30/delays', status: 201, body: { data: delay() } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.delays.add }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(ar.trips.delays.note)).toBeInTheDocument();
    expect(within(dialog).queryByLabelText(ar.trips.delays.currency)).not.toBeInTheDocument();

    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.delays.days}`)), '2');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.delays.cost}`)), '75');
    expect(await within(dialog).findByLabelText(ar.trips.delays.currency)).toBeInTheDocument();
    await user.selectOptions(within(dialog).getByLabelText(ar.trips.delays.responsible), 'customer');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.delays.notes}`)), 'المنفذ مقفول');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/cargo-trips/30/delays')?.body).toEqual({
      days: 2,
      cost: '75',
      currency_id: 1,
      responsible: 'customer',
      notes: 'المنفذ مقفول',
    });
  });

  it('records a plain delay without any cost fields', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/trips/30', {
      user: manager,
      routes: routes([], [], [{ method: 'POST', path: '/cargo-trips/30/delays', status: 201, body: { data: delay() } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.delays.add }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.delays.days}`)), '1');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/cargo-trips/30/delays')?.body).toEqual({ days: 1, responsible: 'company' });
  });

  it('shows the server message next to the field', async () => {
    const user = userEvent.setup();
    renderApp('/trips/30', {
      user: manager,
      routes: routes([], [], [
        { method: 'POST', path: '/cargo-trips/30/delays', status: 422, body: { message: 'x', errors: { days: ['عدد الأيام غير صحيح'] } } },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.delays.add }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.trips.delays.days}`)), '999');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    expect(await within(dialog).findByText('عدد الأيام غير صحيح')).toBeInTheDocument();
  });
});
