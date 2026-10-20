import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import type { ShipmentFile } from '../../shared/api/types';
import { baseRoutes, shipmentFile, treasury } from '../../test/fixtures';
import { fakeUser, page, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const branchStaff = fakeUser({
  'shipment.view': 'branch', 'shipment.create': 'branch', 'shipment.receive': 'branch', 'shipment.cancel': 'branch',
  'shipment.deliver': 'all', 'payment.collect': 'branch', 'treasury.view': 'branch',
});
const owner = fakeUser({ 'shipment.view': 'all', 'shipment.approve_price': 'all', 'shipment.cancel': 'all' });

const fileRoutes = (file: ShipmentFile, extra: MockRoute[] = []): MockRoute[] => [
  ...baseRoutes,
  { path: '/shipments/10', body: { data: file } },
  { path: '/shipments', body: page([]) },
  { path: '/treasuries', body: page([treasury()]) },
  ...extra,
];

const buttons = () => screen.queryAllByRole('button').map((button) => button.textContent);

describe('shipment file', () => {
  it('shows the account, items and payments with the amount still owed', async () => {
    renderApp('/shipments/10', {
      user: branchStaff,
      routes: fileRoutes(shipmentFile({
        status: 'at_branch',
        balance: { currency_id: 1, total_due: '900.00', paid: '400.00', remaining: '500.00', other_currencies: [{ currency_id: 2, net: '20.00' }] },
        payments: [{ id: 1, serial_no: '000001', direction: 'in', kind: 'collection', amount: '400.00', currency_id: 1, treasury_id: 3, notes: null, reversal_of_id: null, created_at: '2026-10-18T10:00:00Z' }],
      })),
    });

    expect(await screen.findByText(ar.shipments.file('000010'))).toBeInTheDocument();
    expect(screen.getByText('ملابس')).toBeInTheDocument();
    expect(screen.getByText('500.00')).toHaveClass('ltr');
    expect(screen.getByText(ar.shipments.otherCurrencies, { exact: false })).toBeInTheDocument();
    expect(screen.getByText(ar.shipments.paymentKind.collection)).toBeInTheDocument();
  });

  it('offers only the actions that fit the draft status and the user', async () => {
    renderApp('/shipments/10', { user: branchStaff, routes: fileRoutes(shipmentFile({ status: 'draft' })) });

    await screen.findByText(ar.shipments.file('000010'));
    expect(buttons()).toEqual(expect.arrayContaining([ar.shipments.submit, ar.common.edit, ar.shipments.cancel, ar.shipments.printForm]));
    expect(buttons()).not.toContain(ar.shipments.collect);
    expect(buttons()).not.toContain(ar.shipments.receive);
  });

  it('lets an approved shipment be received and paid, but not delivered', async () => {
    renderApp('/shipments/10', { user: branchStaff, routes: fileRoutes(shipmentFile({ status: 'approved' })) });

    await screen.findByText(ar.shipments.file('000010'));
    expect(buttons()).toEqual(expect.arrayContaining([ar.shipments.receive, ar.shipments.collect, ar.shipments.scheduleInstallments]));
    expect(buttons()).not.toContain(ar.shipments.deliver);
  });

  it('hides collection once nothing is owed and for users without the permission', async () => {
    renderApp('/shipments/10', {
      user: branchStaff,
      routes: fileRoutes(shipmentFile({ status: 'at_branch', balance: { currency_id: 1, total_due: '900.00', paid: '900.00', remaining: '0.00', other_currencies: [] } })),
    });
    await screen.findByText(ar.shipments.file('000010'));
    expect(buttons()).not.toContain(ar.shipments.collect);
  });

  it('shows delivery only for arrived shipments', async () => {
    renderApp('/shipments/10', { user: branchStaff, routes: fileRoutes(shipmentFile({ status: 'arrived' })) });

    await screen.findByText(ar.shipments.file('000010'));
    expect(buttons()).toContain(ar.shipments.deliver);
    expect(buttons()).not.toContain(ar.shipments.cancel);
  });

  it('lets the owner approve a pending price and refetches the file', async () => {
    const user = userEvent.setup();
    let approved = false;
    const calls = renderApp('/shipments/10', {
      user: owner,
      routes: fileRoutes(shipmentFile(), [
        {
          path: '/shipments/10',
          body: () => ({
            data: shipmentFile({
              status: approved ? 'approved' : 'pending_approval',
              approvals: [{ id: 7, type: 'price', status: approved ? 'approved' : 'pending', value: '1000.00', reason: null }],
            }),
          }),
        },
        {
          method: 'POST',
          path: '/approvals/7/decide',
          body: () => {
            approved = true;

            return { data: {} };
          },
        },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: ar.shipments.approve }));

    expect(await screen.findByText(ar.shipments.status.approved)).toBeInTheDocument();
    expect(calls.find((call) => call.path === '/approvals/7/decide')?.body).toEqual({ decision: 'approve' });
  });

  it('requires a reason to reject', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/10', {
      user: owner,
      routes: fileRoutes(
        shipmentFile({ status: 'pending_approval', approvals: [{ id: 7, type: 'price', status: 'pending', value: '1000.00', reason: null }] }),
        [{ method: 'POST', path: '/approvals/7/decide', body: { data: {} } }],
      ),
    });

    await user.click(await screen.findByRole('button', { name: ar.shipments.reject }));
    const dialog = screen.getByRole('dialog');
    const confirm = within(dialog).getByRole('button', { name: ar.shipments.reject });
    expect(confirm).toBeDisabled();

    await user.type(within(dialog).getByLabelText(ar.shipments.reason), 'السعر غير مناسب');
    await user.click(confirm);

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/approvals/7/decide')?.body).toEqual({ decision: 'reject', reason: 'السعر غير مناسب' });
  });

  it('collects a payment into the chosen treasury', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/10', {
      user: branchStaff,
      routes: fileRoutes(shipmentFile({ status: 'approved' }), [{ method: 'POST', path: '/payments', status: 201, body: { data: {} } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.shipments.collect }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: /نقدي/ });
    await user.type(within(dialog).getByLabelText(new RegExp(ar.shipments.amount)), '300');
    await user.click(within(dialog).getByRole('button', { name: ar.shipments.collect }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/payments')?.body).toEqual({
      shipment_id: 10,
      treasury_id: 3,
      amount: '300',
    });
  });

  it('warns when the treasury is in another currency than the shipment', async () => {
    const user = userEvent.setup();
    renderApp('/shipments/10', {
      user: branchStaff,
      routes: [
        ...fileRoutes(shipmentFile({ status: 'approved' })),
        { path: '/treasuries', body: page([treasury({ id: 4, currency_id: 2, currency: { id: 2, code: 'USD', name_ar: 'دولار' } })]) },
      ],
    });

    await user.click(await screen.findByRole('button', { name: ar.shipments.collect }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: /دولار/ });
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(ar.shipments.treasury)), '4');

    expect(within(dialog).getByText(ar.shipments.differentCurrency)).toBeInTheDocument();
  });

  it('shows the server message when a payment is refused', async () => {
    const user = userEvent.setup();
    renderApp('/shipments/10', {
      user: branchStaff,
      routes: fileRoutes(shipmentFile({ status: 'approved' }), [
        { method: 'POST', path: '/payments', status: 422, body: { message: 'x', errors: { amount: ['المبلغ أكبر من المتبقي على الشحنة'] } } },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: ar.shipments.collect }));
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByRole('option', { name: /نقدي/ });
    await user.type(within(dialog).getByLabelText(new RegExp(ar.shipments.amount)), '99999');
    await user.click(within(dialog).getByRole('button', { name: ar.shipments.collect }));

    expect(await within(dialog).findByText('المبلغ أكبر من المتبقي على الشحنة')).toBeInTheDocument();
  });

  it('cancels with a reason', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/10', {
      user: branchStaff,
      routes: fileRoutes(shipmentFile({ status: 'at_branch' }), [{ method: 'POST', path: '/shipments/10/cancel', body: { data: {} } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.shipments.cancel }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(ar.shipments.cancelWarning)).toBeInTheDocument();
    await user.type(within(dialog).getByLabelText(ar.shipments.cancelReason), 'العميل غيّر رأيه');
    await user.click(within(dialog).getByRole('button', { name: ar.shipments.cancel }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/shipments/10/cancel')?.body).toEqual({ reason: 'العميل غيّر رأيه' });
  });

  it('schedules installments', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/10', {
      user: branchStaff,
      routes: fileRoutes(shipmentFile({ status: 'approved' }), [{ method: 'POST', path: '/shipments/10/installments', body: { data: {} } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.shipments.scheduleInstallments }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(`${ar.shipments.dueDate} 1`), '2026-12-01');
    await user.type(within(dialog).getByLabelText(`${ar.shipments.amount} 1`), '450');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/shipments/10/installments')?.body).toEqual({
      installments: [{ due_date: '2026-12-01', amount: '450' }],
    });
  });

  it('delivers to a named receiver and surfaces the balance rule from the server', async () => {
    const user = userEvent.setup();
    renderApp('/shipments/10', {
      user: branchStaff,
      routes: fileRoutes(shipmentFile({ status: 'arrived' }), [
        { method: 'POST', path: '/shipments/10/deliver', status: 422, body: { message: 'x', errors: { balance: ['عليها متبقي 900.00 مينفعش تتسلم'] } } },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: ar.shipments.deliver }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(ar.shipments.receiverName), 'محمد');
    await user.click(within(dialog).getByRole('button', { name: ar.shipments.deliver }));

    expect(await within(dialog).findByText('عليها متبقي 900.00 مينفعش تتسلم')).toBeInTheDocument();
  });
});

describe('printing', () => {
  const tab = { location: { href: '' }, close: vi.fn() };

  beforeEach(() => {
    tab.location.href = '';
    tab.close.mockClear();
    vi.spyOn(window, 'open').mockReturnValue(tab as unknown as Window);
    URL.createObjectURL = vi.fn(() => 'blob:pdf-url');
    URL.revokeObjectURL = vi.fn();
  });

  it('generates the shipment form and opens the PDF in the tab opened by the click', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/10', {
      user: branchStaff,
      routes: fileRoutes(shipmentFile(), [
        { method: 'POST', path: '/documents', status: 201, body: { data: { id: 55 } } },
        { path: '/documents/55/download', body: { pdf: true } },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: ar.shipments.printForm }));

    await waitFor(() => expect(tab.location.href).toBe('blob:pdf-url'));
    expect(calls.find((call) => call.path === '/documents')?.body).toEqual({ type: 'shipment_form', subject_id: 10 });
    expect(calls.some((call) => call.path === '/documents/55/download')).toBe(true);
  });

  it('prints a receipt for a collection payment and closes the tab if generation fails', async () => {
    const user = userEvent.setup();
    renderApp('/shipments/10', {
      user: branchStaff,
      routes: fileRoutes(
        shipmentFile({
          status: 'at_branch',
          payments: [{ id: 21, serial_no: '000003', direction: 'in', kind: 'collection', amount: '100.00', currency_id: 1, treasury_id: 3, notes: null, reversal_of_id: null, created_at: '2026-10-18T10:00:00Z' }],
        }),
        [{ method: 'POST', path: '/documents', status: 500, body: { message: 'boom' } }],
      ),
    });

    await user.click(await screen.findByRole('button', { name: ar.shipments.printReceipt }));

    await waitFor(() => expect(tab.close).toHaveBeenCalled());
    expect(await screen.findByRole('alert')).toBeInTheDocument();
  });

  it('offers no receipt for a payment that was reversed', async () => {
    renderApp('/shipments/10', {
      user: branchStaff,
      routes: fileRoutes(
        shipmentFile({
          status: 'at_branch',
          payments: [
            { id: 21, serial_no: '000003', direction: 'in', kind: 'collection', amount: '100.00', currency_id: 1, treasury_id: 3, notes: null, reversal_of_id: null, created_at: '2026-10-18T10:00:00Z' },
            { id: 22, serial_no: '000004', direction: 'out', kind: 'reversal', amount: '100.00', currency_id: 1, treasury_id: 3, notes: null, reversal_of_id: 21, created_at: '2026-10-18T11:00:00Z' },
          ],
        }),
      ),
    });

    await screen.findByText(ar.shipments.reversed);
    expect(screen.queryByRole('button', { name: ar.shipments.printReceipt })).not.toBeInTheDocument();
  });
});

describe('express shipment file', () => {
  it('links to the bus trip the shipment rides, and only for users who may open trips', async () => {
    const file = shipmentFile({
      type: 'express',
      status: 'at_branch',
      bus_trip_id: 40,
      bus_trip: { id: 40, kind: 'bus', route: 'القاهرة - أسوان', depart_at: '2099-11-01T06:00:00Z', status: 'open' },
    });

    renderApp('/shipments/10', { user: fakeUser({ 'shipment.view': 'all', 'trip.view': 'all' }), routes: fileRoutes(file) });
    expect(await screen.findByRole('link', { name: 'القاهرة - أسوان' })).toHaveAttribute('href', '/passenger-trips/40');
  });

  it('shows the route as plain text without trip access', async () => {
    const file = shipmentFile({
      type: 'express',
      status: 'at_branch',
      bus_trip_id: 40,
      bus_trip: { id: 40, kind: 'bus', route: 'القاهرة - أسوان', depart_at: '2099-11-01T06:00:00Z', status: 'open' },
    });

    renderApp('/shipments/10', { user: owner, routes: fileRoutes(file) });
    expect(await screen.findByText(/القاهرة - أسوان/)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'القاهرة - أسوان' })).not.toBeInTheDocument();
  });
});
