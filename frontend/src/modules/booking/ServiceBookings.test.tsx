import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import type { ServiceBooking } from '../../shared/api/types';
import { baseRoutes, treasury } from '../../test/fixtures';
import { fakeUser, page, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const counter = fakeUser({ 'booking.view': 'branch', 'booking.create': 'all', 'booking.cancel_refund': 'branch', 'customer.view': 'all', 'treasury.view': 'branch' });
const office = fakeUser({ 'booking.view': 'own', 'booking.create': 'own', 'customer.view': 'own' }, { branch: null, agent: { id: 4, name: 'مكتب النور' } });

const service = (overrides: Partial<ServiceBooking> = {}): ServiceBooking => ({
  id: 80,
  serial_no: '000080',
  type: 'hotel',
  customer_id: 5,
  details: 'فندق النيل، غرفتين',
  start_date: '2099-12-01',
  end_date: '2099-12-04',
  price: '4500.00',
  currency_id: 1,
  status: 'reserved',
  cancel_reason: null,
  paid: '0.00',
  customer: { id: 5, name: 'سعد محمود', phone: '+201012345678' },
  ...overrides,
});

const routes = (items: ServiceBooking[], extra: MockRoute[] = []): MockRoute[] => [
  ...baseRoutes,
  { path: '/service-bookings', body: page(items) },
  { path: '/treasuries', body: page([treasury({ id: 8, currency_id: 1 }), treasury({ id: 9, currency_id: 2 })]) },
  { path: '/customers', body: page([{ id: 5, name: 'سعد محمود', phone: '+201012345678', passport_no: null, country: 'EG', whatsapp_verified_at: null, active: true }]) },
  ...extra,
];

describe('hotel and car bookings', () => {
  it('lists bookings and filters on the server', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/service-bookings', { user: counter, routes: routes([service()]) });

    expect(await screen.findByText('فندق النيل، غرفتين')).toBeInTheDocument();
    expect(within(screen.getByRole('table')).getByText(ar.services.status.reserved)).toBeInTheDocument();

    await user.selectOptions(screen.getByRole('combobox', { name: ar.services.type }), 'car');
    await user.selectOptions(screen.getByRole('combobox', { name: ar.common.status }), 'paid');
    await waitFor(() => {
      const last = calls.filter((call) => call.path === '/service-bookings').at(-1)?.query;
      expect(last?.get('type')).toBe('car');
      expect(last?.get('status')).toBe('paid');
    });
  });

  it('creates a booking paid into the only treasury in its currency', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/service-bookings', {
      user: counter,
      routes: routes([], [{ method: 'POST', path: '/service-bookings', status: 201, body: { data: service({ status: 'paid' }) } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.services.new }));
    const dialog = screen.getByRole('dialog');
    const submit = within(dialog).getByRole('button', { name: ar.services.create });
    expect(submit).toBeDisabled();

    await user.type(within(dialog).getByLabelText(ar.shipments.customer), '0101');
    await user.click(await within(dialog).findByRole('button', { name: /سعد محمود/ }));
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.services.details}`)), 'فندق النيل، غرفتين');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.services.price}`)), '4500');
    await screen.findAllByRole('option', { name: /نقدي/ });
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(ar.services.treasury)), '8');
    await waitFor(() => expect(submit).toBeEnabled());
    await user.click(submit);

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/service-bookings')?.body).toEqual({
      type: 'hotel',
      customer_id: 5,
      details: 'فندق النيل، غرفتين',
      price: '4500',
      currency_id: 1,
      treasury_id: 8,
    });
  });

  it('keeps a booking reserved when no treasury is chosen, and collects it later', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/service-bookings', {
      user: counter,
      routes: routes([service()], [{ method: 'POST', path: '/service-bookings/80/collect', body: { data: service({ status: 'paid', paid: '4500.00' }) } }]),
    });

    await user.click(await screen.findByRole('button', { name: `${ar.services.collect} 000080` }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/4,500.00/)).toBeInTheDocument();
    await screen.findAllByRole('option', { name: /نقدي/ });
    // The single treasury in the booking's currency is picked for the clerk.
    await waitFor(() => expect(within(dialog).getByRole('button', { name: ar.services.collect })).toBeEnabled());
    await user.click(within(dialog).getByRole('button', { name: ar.services.collect }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/service-bookings/80/collect')?.body).toEqual({ treasury_id: 8 });
  });

  it('completes a paid booking and cancels one with a reason', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/service-bookings', {
      user: counter,
      routes: routes([service({ id: 81, serial_no: '000081', status: 'paid', paid: '4500.00' })], [
        { method: 'POST', path: '/service-bookings/81/complete', body: { data: service({ status: 'completed' }) } },
        { method: 'POST', path: '/service-bookings/81/cancel', body: { data: service({ status: 'cancelled' }) } },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: `${ar.services.complete} 000081` }));
    await waitFor(() => expect(calls.some((call) => call.path === '/service-bookings/81/complete')).toBe(true));

    await user.click(screen.getByRole('button', { name: `${ar.booking.cancelBooking} 000081` }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(ar.booking.cancelReason), 'العميل لغى');
    await user.click(within(dialog).getByRole('button', { name: ar.booking.cancelBooking }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/service-bookings/81/cancel')?.body).toEqual({ reason: 'العميل لغى' });
  });

  it('offers no actions on a cancelled booking', async () => {
    renderApp('/service-bookings', { user: counter, routes: routes([service({ status: 'cancelled' })]) });

    await screen.findByText('فندق النيل، غرفتين');
    expect(screen.queryByRole('button', { name: new RegExp(ar.services.collect) })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: new RegExp(ar.booking.cancelBooking) })).not.toBeInTheDocument();
  });

  it('lets an office book on account without choosing a treasury, and never collect', async () => {
    const user = userEvent.setup();
    renderApp('/service-bookings', { user: office, routes: routes([service()]) });

    await screen.findByText('فندق النيل، غرفتين');
    expect(screen.queryByRole('button', { name: `${ar.services.collect} 000080` })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: ar.services.new }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(ar.services.officeHint)).toBeInTheDocument();
    expect(within(dialog).queryByLabelText(new RegExp(ar.services.treasury))).not.toBeInTheDocument();
  });
});
