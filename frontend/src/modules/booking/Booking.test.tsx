import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { baseRoutes, booking, passengerTrip, seatMap, treasury } from '../../test/fixtures';
import { fakeUser, page, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const counter = fakeUser({ 'trip.view': 'all', 'booking.view': 'branch', 'booking.create': 'all', 'booking.cancel_refund': 'branch', 'treasury.view': 'branch', 'customer.view': 'all' });
const manager = fakeUser({ 'trip.view': 'all', 'trip.manage': 'all', 'booking.view': 'all', 'masterdata.view': 'all' });
const office = fakeUser({ 'trip.view': 'all', 'booking.view': 'own', 'booking.create': 'own' }, { branch: null, agent: { id: 4, name: 'مكتب النور' } });

const treasuries: MockRoute = { path: '/treasuries', body: page([treasury({ id: 8, currency_id: 1 }), treasury({ id: 9, currency_id: 2 })]) };

describe('passenger trips list', () => {
  it('lists trips with booked seats and filters on the server', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/passenger-trips', { user: counter, routes: [...baseRoutes, { path: '/trips', body: page([passengerTrip()]) }] });

    expect(await screen.findByRole('link', { name: 'القاهرة - أسوان' })).toHaveAttribute('href', '/passenger-trips/40');
    expect(screen.getByText(ar.booking.seatsOf(1, 4))).toBeInTheDocument();

    await user.selectOptions(screen.getByRole('combobox', { name: ar.booking.columns.kind }), 'flight');
    await user.click(screen.getByRole('checkbox', { name: ar.booking.onlySellable }));
    await waitFor(() => {
      const last = calls.filter((call) => call.path === '/trips').at(-1)?.query;
      expect(last?.get('kind')).toBe('flight');
      expect(last?.get('sellable')).toBe('1');
    });
  });

  it('shows the new-trip button only to those who manage trips', async () => {
    renderApp('/passenger-trips', { user: counter, routes: [...baseRoutes, { path: '/trips', body: page([]) }] });
    expect(await screen.findByText(ar.common.empty)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.booking.new })).not.toBeInTheDocument();
  });
});

describe('new passenger trip', () => {
  it('sends the trip and opens it, taking the seat count from the chosen bus', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/passenger-trips/new', {
      user: manager,
      routes: [
        ...baseRoutes,
        { path: '/vehicles', body: page([{ id: 3, plate_no: 'BUS 1', category: 'bus', body: null, max_ton: null, seats: 45, active: true }]) },
        { path: '/drivers', body: page([{ id: 2, name: 'حسن إبراهيم', phone: null, active: true }]) },
        { method: 'POST', path: '/trips', status: 201, body: { data: passengerTrip() } },
        { path: '/trips/40/seats', body: seatMap() },
      ],
    });

    await screen.findByRole('option', { name: /BUS 1/ });
    await user.type(screen.getByLabelText(new RegExp(`^${ar.booking.route}`)), 'القاهرة - أسوان');
    await user.type(screen.getByLabelText(new RegExp(`^${ar.booking.departAt}`)), '2099-11-01T08:00');
    await user.selectOptions(screen.getByLabelText(new RegExp(`^${ar.booking.vehicle}`)), '3');
    expect(screen.getByLabelText(new RegExp(`^${ar.booking.passengerSeats}`))).toHaveValue('45');
    await user.type(screen.getByLabelText(new RegExp(`^${ar.booking.price}`)), '500');
    await user.click(screen.getByRole('button', { name: ar.booking.create }));

    expect(await screen.findByText(ar.booking.file('القاهرة - أسوان'))).toBeInTheDocument();
    const body = calls.find((call) => call.method === 'POST' && call.path === '/trips')?.body;
    expect(body).toMatchObject({ kind: 'bus', route: 'القاهرة - أسوان', vehicle_id: 3, passenger_seats: 45, express_seats: 0, price: '500', currency_id: 1 });
    expect(body.depart_at).toMatch(/^2099-11-01T\d\d:00:00\.000Z$/);
  });

  it('cannot be submitted without a route, a time, seats and a price', async () => {
    renderApp('/passenger-trips/new', { user: manager, routes: [...baseRoutes, { path: '/vehicles', body: page([]) }, { path: '/drivers', body: page([]) }] });
    expect(await screen.findByRole('button', { name: ar.booking.create })).toBeDisabled();
  });
});

describe('seat map', () => {
  const tab = { location: { href: '' }, close: vi.fn() };

  beforeEach(() => {
    tab.location.href = '';
    vi.spyOn(window, 'open').mockReturnValue(tab as unknown as Window);
    URL.createObjectURL = vi.fn(() => 'blob:pdf-url');
    URL.revokeObjectURL = vi.fn();
  });

  const fileRoutes = (map = seatMap(), extra: MockRoute[] = []): MockRoute[] => [...baseRoutes, treasuries, { path: '/trips/40/seats', body: map }, ...extra];

  it('draws every seat, free or booked', async () => {
    renderApp('/passenger-trips/40', { user: counter, routes: fileRoutes() });

    expect(await screen.findByRole('button', { name: ar.booking.bookedSeatLabel(1, 'منى علي') })).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /فاضي/ })).toHaveLength(3);
  });

  it('books a free seat into the only treasury in the trip currency', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/passenger-trips/40', {
      user: counter,
      routes: fileRoutes(seatMap(), [{ method: 'POST', path: '/bookings', status: 201, body: { data: booking({ seat_no: 2 }) } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.booking.freeSeatLabel(2) }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(ar.booking.bookTitle(2))).toBeInTheDocument();
    const submit = within(dialog).getByRole('button', { name: ar.booking.bookNow });
    expect(submit).toBeDisabled();

    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.booking.passengerName}`)), 'سمير حسن');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.booking.passport}`)), 'B777');
    await waitFor(() => expect(submit).toBeEnabled());
    await user.click(submit);

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/bookings')?.body).toEqual({
      trip_id: 40,
      seat_no: 2,
      passenger_name: 'سمير حسن',
      passport_no: 'B777',
      treasury_id: 8,
    });
  });

  it('tells the clerk when someone took the seat a moment ago', async () => {
    const user = userEvent.setup();
    renderApp('/passenger-trips/40', {
      user: counter,
      routes: fileRoutes(seatMap(), [{ method: 'POST', path: '/bookings', status: 422, body: { message: 'x', errors: { seat_no: ['الكرسي اتحجز قبل كده'] } } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.booking.freeSeatLabel(3) }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.booking.passengerName}`)), 'سمير حسن');
    await waitFor(() => expect(within(dialog).getByRole('button', { name: ar.booking.bookNow })).toBeEnabled());
    await user.click(within(dialog).getByRole('button', { name: ar.booking.bookNow }));

    expect(await within(dialog).findByText('الكرسي اتحجز قبل كده')).toBeInTheDocument();
  });

  it('lets an external office set its selling price and sends no treasury', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/passenger-trips/40', {
      user: office,
      routes: fileRoutes(seatMap({}, false), [{ method: 'POST', path: '/bookings', status: 201, body: { data: booking({ status: 'reserved' }) } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.booking.freeSeatLabel(2) }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).queryByLabelText(new RegExp(ar.booking.treasury))).not.toBeInTheDocument();
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.booking.passengerName}`)), 'سمير حسن');
    const price = within(dialog).getByLabelText(new RegExp(`^${ar.booking.salePrice}`));
    await user.clear(price);
    await user.type(price, '650');
    await user.click(within(dialog).getByRole('button', { name: ar.booking.bookNow }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/bookings')?.body).toEqual({
      trip_id: 40,
      seat_no: 2,
      passenger_name: 'سمير حسن',
      sale_price: '650',
    });
  });

  it('does not show passenger names or open booked seats to users without booking.view', async () => {
    renderApp('/passenger-trips/40', { user: fakeUser({ 'trip.view': 'all' }), routes: fileRoutes(seatMap({}, false)) });

    const seat = await screen.findByRole('button', { name: ar.booking.bookedSeatLabel(1) });
    expect(seat).toBeDisabled();
    expect(screen.queryByText('منى علي')).not.toBeInTheDocument();
  });

  it('prints the ticket and cancels a booking with a reason', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/passenger-trips/40', {
      user: counter,
      routes: fileRoutes(seatMap(), [
        { method: 'POST', path: '/documents', status: 201, body: { data: { id: 55 } } },
        { path: '/documents/55/download', body: { pdf: true } },
        { method: 'POST', path: '/bookings/60/cancel', body: { data: booking({ status: 'cancelled' }) } },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: ar.booking.bookedSeatLabel(1, 'منى علي') }));
    let dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('A123')).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: ar.booking.printTicket }));
    await waitFor(() => expect(tab.location.href).toBe('blob:pdf-url'));
    expect(calls.find((call) => call.path === '/documents')?.body).toEqual({ type: 'bus_ticket', subject_id: 60 });

    await user.click(within(dialog).getByRole('button', { name: ar.booking.cancelBooking }));
    dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(ar.booking.cancelReason), 'الراكب غيّر رأيه');
    await user.click(within(dialog).getByRole('button', { name: ar.booking.cancelBooking }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/bookings/60/cancel')?.body).toEqual({ reason: 'الراكب غيّر رأيه' });
  });

  it('stops selling and cancelling once the trip has departed, and offers the manifest', async () => {
    renderApp('/passenger-trips/40', { user: manager, routes: fileRoutes(seatMap({ status: 'departed' })) });

    await screen.findByText(ar.booking.file('القاهرة - أسوان'));
    expect(screen.getByRole('button', { name: ar.booking.printManifest })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.booking.depart })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.booking.cancelTrip })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: ar.booking.complete })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: ar.booking.freeSeatLabel(2) })).toBeDisabled();
  });

  it('departs the trip after confirmation', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/passenger-trips/40', {
      user: manager,
      routes: fileRoutes(seatMap(), [{ method: 'POST', path: '/trips/40/depart', body: { data: passengerTrip({ status: 'departed' }) } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.booking.depart }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(ar.booking.departConfirm)).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: ar.booking.depart }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.some((call) => call.method === 'POST' && call.path === '/trips/40/depart')).toBe(true);
  });
});

describe('bookings list', () => {
  const routes: MockRoute[] = [...baseRoutes, { path: '/bookings', body: page([booking(), booking({ id: 61, serial_no: '000061', status: 'cancelled' })]) }];

  it('lists bookings and offers cancel only on live ones', async () => {
    renderApp('/bookings', { user: counter, routes });

    expect(await screen.findByText('000060')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: `${ar.booking.cancelBooking} 000060` })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: `${ar.booking.cancelBooking} 000061` })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: `${ar.booking.printTicket} 000061` })).not.toBeInTheDocument();
  });

  it('searches and filters by status on the server', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/bookings', { user: counter, routes });

    await screen.findByText('000060');
    await user.type(screen.getByRole('textbox'), 'منى');
    await user.selectOptions(screen.getByRole('combobox', { name: ar.common.status }), 'paid');
    await waitFor(() => {
      const last = calls.filter((call) => call.path === '/bookings').at(-1)?.query;
      expect(last?.get('q')).toBe('منى');
      expect(last?.get('status')).toBe('paid');
    });
  });
});

describe('express seats on the trip file', () => {
  const viewer = fakeUser({ 'trip.view': 'all', 'shipment.view': 'all' });
  const express = { id: 70, serial_no: '000070', tracking_code: 'EXPRESS001', status: 'at_branch', consignee_name: 'عمر الطيب', consignee_phone: '+249912345678', currency_id: 1, price: '300.00', discount: '0.00', customer: { id: 5, name: 'سعد محمود' }, destination: { id: 1, name: 'أم درمان' }, branch: { id: 1, name: 'فرع القاهرة' } };

  it('shows the used and free express seats with the shipments riding the trip', async () => {
    renderApp('/passenger-trips/40', {
      user: viewer,
      routes: [
        ...baseRoutes,
        { path: '/trips/40/seats', body: seatMap({ express_seats: 3, express_used: 1, express_free: 2 }) },
        { path: '/trips/40/express-shipments', body: { data: [express] } },
      ],
    });

    expect(await screen.findByText(ar.booking.expressUsage(1, 3))).toBeInTheDocument();
    expect(screen.getByText(new RegExp(ar.booking.expressFree))).toBeInTheDocument();
    expect(await screen.findByRole('link', { name: '000070' })).toHaveAttribute('href', '/shipments/70');
    expect(screen.getByText('سعد محمود')).toBeInTheDocument();
  });

  it('after the cutoff shows the locked notice and the seats released to passengers', async () => {
    renderApp('/passenger-trips/40', {
      user: viewer,
      routes: [
        ...baseRoutes,
        {
          path: '/trips/40/seats',
          body: seatMap({ status: 'express_locked', express_seats: 3, express_used: 1, express_free: 0, express_locked: true, express_released: 2, sellable_seats: 6, passenger_seats: 4 }),
        },
        { path: '/trips/40/express-shipments', body: { data: [] } },
      ],
    });

    expect(await screen.findByText(ar.booking.expressLockedHint)).toBeInTheDocument();
    expect(screen.getByText(ar.booking.expressReleased(2))).toBeInTheDocument();
    expect(screen.getByText(ar.booking.seatsOf(1, 6))).toBeInTheDocument();
    expect(await screen.findByText(ar.booking.expressEmpty)).toBeInTheDocument();
  });

  it('hides the shipment list from users who cannot view shipments and the section from trips without express seats', async () => {
    const calls = renderApp('/passenger-trips/40', {
      user: fakeUser({ 'trip.view': 'all' }),
      routes: [...baseRoutes, { path: '/trips/40/seats', body: seatMap({ express_seats: 2, express_free: 2 }) }],
    });

    expect(await screen.findByText(ar.booking.expressUsage(0, 2))).toBeInTheDocument();
    expect(calls.some((call) => call.path === '/trips/40/express-shipments')).toBe(false);
    expect(screen.queryByText(ar.booking.expressEmpty)).not.toBeInTheDocument();
  });

  it('draws no express section for a trip without express seats', async () => {
    renderApp('/passenger-trips/40', { user: viewer, routes: [...baseRoutes, { path: '/trips/40/seats', body: seatMap() }] });

    await screen.findByText(ar.booking.seatMap);
    expect(screen.queryByText(ar.booking.expressTitle)).not.toBeInTheDocument();
  });
});
