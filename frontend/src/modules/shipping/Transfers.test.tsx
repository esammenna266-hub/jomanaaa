import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { baseRoutes, summary, transfer } from '../../test/fixtures';
import { fakeUser, page, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const branchStaff = fakeUser({ 'transfer.create': 'branch', 'shipment.view': 'branch' });
const faisal = fakeUser({ 'transfer.confirm_receipt': 'all', 'shipment.view': 'all' }, { branch: { id: 9, name: 'مخزن فيصل' } });
const owner = fakeUser({ 'transfer.create': 'all', 'transfer.confirm_receipt': 'all', 'shipment.view': 'all' });

const ready = [
  summary({ id: 10, serial_no: '000010', status: 'at_branch' }),
  summary({ id: 11, serial_no: '000011', status: 'at_branch', consignee_name: 'ياسر عثمان' }),
];

describe('transfers list', () => {
  it('lists exit forms with their status and flags the ones received with a difference', async () => {
    renderApp('/transfers', {
      user: faisal,
      routes: [...baseRoutes, { path: '/transfers', body: page([transfer(), transfer({ id: 21, serial_no: '000021', status: 'received_with_diff' })]) }],
    });

    expect(await screen.findByRole('link', { name: '000020' })).toHaveAttribute('href', '/transfers/20');
    const table = within(screen.getByRole('table'));
    expect(table.getByText(ar.transfers.status.sent)).toBeInTheDocument();
    expect(table.getByText(ar.transfers.status.received_with_diff)).toBeInTheDocument();
  });

  it('opens the form behind a scanned barcode', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/transfers', {
      user: faisal,
      routes: [
        { path: '/transfers', body: page([]) },
        { path: '/transfers/lookup', body: { data: transfer() } },
        { path: '/transfers/20', body: { data: transfer() } },
      ],
    });

    await user.type(await screen.findByLabelText(ar.transfers.scanLabel), 'ABCDE12345{Enter}');

    expect(await screen.findByText(ar.transfers.file('000020'))).toBeInTheDocument();
    expect(calls.find((call) => call.path === '/transfers/lookup')?.query.get('code')).toBe('ABCDE12345');
  });

  it('says so when no open form matches the scanned code', async () => {
    const user = userEvent.setup();
    renderApp('/transfers', {
      user: faisal,
      routes: [
        { path: '/transfers', body: page([]) },
        { path: '/transfers/lookup', status: 404, body: { message: 'Not found' } },
      ],
    });

    await user.type(await screen.findByLabelText(ar.transfers.scanLabel), 'NOPE{Enter}');

    expect(await screen.findByText(ar.transfers.scanNotFound)).toBeInTheDocument();
  });

  it('gives branch staff the new-form button but no scan box', async () => {
    renderApp('/transfers', { user: branchStaff, routes: [{ path: '/transfers', body: page([]) }] });

    expect(await screen.findByRole('button', { name: ar.transfers.new })).toBeInTheDocument();
    expect(screen.queryByLabelText(ar.transfers.scanLabel)).not.toBeInTheDocument();
  });
});

describe('new exit form', () => {
  const routes = (extra: MockRoute[] = []): MockRoute[] => [
    { path: '/shipments', body: page(ready) },
    { path: '/transfers', body: page([]) },
    { path: '/transfers/20', body: { data: transfer() } },
    ...extra,
  ];

  it('sends the chosen shipments with the counts that were typed, and opens the new form', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/transfers/new', {
      user: branchStaff,
      routes: routes([{ method: 'POST', path: '/transfers', status: 201, body: { data: transfer() } }]),
    });

    await user.click(await screen.findByRole('checkbox', { name: '000010' }));
    await user.click(screen.getByRole('checkbox', { name: '000011' }));
    await user.type(screen.getByLabelText(`${ar.transfers.sentCount} 000010`), '12');
    await user.type(screen.getByLabelText(new RegExp(`^${ar.transfers.driver}`)), 'حسن');
    await user.click(screen.getByRole('button', { name: ar.transfers.create }));

    expect(await screen.findByText(ar.transfers.file('000020'))).toBeInTheDocument();
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({
      shipments: [{ shipment_id: 10, sent_count: '12' }, { shipment_id: 11 }],
      vehicle_text: null,
      driver_text: 'حسن',
      driver_phone: null,
    });
    expect(calls.find((call) => call.path === '/shipments')?.query.get('status')).toBe('at_branch');
  });

  it('will not send an empty form', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/transfers/new', { user: branchStaff, routes: routes() });

    await user.click(await screen.findByRole('button', { name: ar.transfers.create }));

    expect(await screen.findByText(ar.transfers.pickAtLeastOne)).toBeInTheDocument();
    expect(calls.some((call) => call.method === 'POST')).toBe(false);
  });

  it('tells the employee when nothing at the branch is ready', async () => {
    renderApp('/transfers/new', { user: branchStaff, routes: [{ path: '/shipments', body: page([]) }] });

    expect(await screen.findByText(ar.transfers.noShipments)).toBeInTheDocument();
  });

  it('asks the owner for a branch first, then loads that branch\'s shipments', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/transfers/new', {
      user: owner,
      routes: routes([{ path: '/branches', body: page([{ id: 1, name: 'فرع القاهرة', country: 'EG', timezone: 'x', is_main_warehouse: false, phone: null, address: null, active: true }]) }]),
    });

    await screen.findByRole('option', { name: 'فرع القاهرة' });
    expect(calls.some((call) => call.path === '/shipments')).toBe(false);
    await user.selectOptions(screen.getByLabelText(new RegExp(ar.transfers.branch)), '1');

    await screen.findByRole('checkbox', { name: '000010' });
    expect(calls.find((call) => call.path === '/shipments')?.query.get('branch_id')).toBe('1');
  });
});

describe('exit form at the warehouse', () => {
  it('confirms a line when its barcode is scanned and receives with the counts', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/transfers/20', {
      user: faisal,
      routes: [
        { path: '/transfers/20', body: { data: transfer() } },
        { path: '/transfers', body: page([]) },
        { method: 'POST', path: '/transfers/20/receive', body: { data: transfer({ status: 'received' }) } },
      ],
    });

    const confirm = await screen.findByRole('button', { name: ar.transfers.confirmReceive });
    expect(confirm).toBeDisabled();

    await user.type(screen.getByLabelText(ar.transfers.scanShipment), 'ABCDE12345{Enter}');
    expect(await screen.findByText(ar.transfers.scanned('000010'))).toBeInTheDocument();
    expect(screen.getByLabelText(`${ar.transfers.lines.received} 000010`)).toHaveValue('10');

    await user.type(screen.getByLabelText(ar.transfers.scanShipment), 'ZZZZZ99999{Enter}');
    expect(confirm).toBeEnabled();
    await user.click(confirm);

    await waitFor(() => expect(calls.some((call) => call.path === '/transfers/20/receive')).toBe(true));
    expect(calls.find((call) => call.path === '/transfers/20/receive')?.body).toEqual({
      items: [{ shipment_id: 10, received_count: '10' }, { shipment_id: 11, received_count: '4' }],
    });
  });

  it('shows a shortage in red as soon as a smaller count is typed', async () => {
    const user = userEvent.setup();
    renderApp('/transfers/20', { user: faisal, routes: [{ path: '/transfers/20', body: { data: transfer() } }] });

    await user.type(await screen.findByLabelText(`${ar.transfers.lines.received} 000010`), '8');
    await user.type(screen.getByLabelText(`${ar.transfers.lines.received} 000011`), '4');

    const row = screen.getByText('000010').closest('tr')!;
    expect(within(row).getByText(`-2 (${ar.transfers.shortage})`)).toBeInTheDocument();
    expect(row).toHaveClass('row--alert');
    expect(screen.getByText(ar.transfers.diffWarning(1))).toBeInTheDocument();
    expect(screen.getByText('000011').closest('tr')).not.toHaveClass('row--alert');
  });

  it('rejects a scanned code that is not on the form', async () => {
    const user = userEvent.setup();
    renderApp('/transfers/20', { user: faisal, routes: [{ path: '/transfers/20', body: { data: transfer() } }] });

    await user.type(await screen.findByLabelText(ar.transfers.scanShipment), 'OTHER{Enter}');

    expect(await screen.findByText(ar.transfers.scanUnknown)).toBeInTheDocument();
  });

  it('can mark every line as matching in one click', async () => {
    const user = userEvent.setup();
    renderApp('/transfers/20', { user: faisal, routes: [{ path: '/transfers/20', body: { data: transfer() } }] });

    await user.click(await screen.findByRole('button', { name: ar.transfers.receiveAll }));

    expect(screen.getByRole('button', { name: ar.transfers.confirmReceive })).toBeEnabled();
    expect(screen.getByLabelText(`${ar.transfers.lines.received} 000011`)).toHaveValue('4');
  });

  it('shows a received form with its recorded differences and the alert, read-only', async () => {
    const received = transfer({
      status: 'received_with_diff',
      has_difference: true,
      items: transfer().items.map((item, index) => ({
        ...item,
        received_count: index === 0 ? '8.00' : '4.00',
        difference: index === 0 ? '-2.00' : '0.00',
      })),
    });
    renderApp('/transfers/20', { user: faisal, routes: [{ path: '/transfers/20', body: { data: received } }] });

    expect(await screen.findByText(ar.transfers.differenceAlert)).toBeInTheDocument();
    expect(screen.getByText(`-2 (${ar.transfers.shortage})`)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.transfers.confirmReceive })).not.toBeInTheDocument();
    expect(screen.queryByLabelText(ar.transfers.scanShipment)).not.toBeInTheDocument();
  });

  it('does not let a branch employee receive their own form', async () => {
    renderApp('/transfers/20', { user: branchStaff, routes: [{ path: '/transfers/20', body: { data: transfer() } }] });

    await screen.findByText(ar.transfers.file('000020'));
    expect(screen.getByText('000020', { selector: '.barcode' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.transfers.confirmReceive })).not.toBeInTheDocument();
  });
});
