import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { baseRoutes, summary, trip } from '../../test/fixtures';
import { fakeUser, page, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const warehouse = fakeUser({ 'cargo_trip.manage': 'all', 'tracking.update': 'all', 'masterdata.view': 'all', 'shipment.view': 'all' });
const trackerOnly = fakeUser({ 'tracking.update': 'all' });

const masterData: MockRoute[] = [
  ...baseRoutes,
  { path: '/vehicles', body: page([{ id: 1, plate_no: 'TRK 1', category: 'jumbo', body: 'closed', max_ton: '20', active: true }, { id: 2, plate_no: 'BUS 1', category: 'bus', body: null, max_ton: null, active: true }]) },
  { path: '/drivers', body: page([{ id: 2, name: 'حسن إبراهيم', phone: '+201098765432', active: true }]) },
  { path: '/border-points', body: page([{ id: 2, name: 'أرقين' }]) },
  { path: '/clearing-agents', body: page([]) },
  { path: '/shipments', body: page([summary({ id: 10, serial_no: '000010', status: 'at_main_warehouse' }), summary({ id: 12, serial_no: '000012', status: 'at_main_warehouse', type: 'express' })]) },
];

describe('trips list', () => {
  it('lists trips with their stage and filters by stage', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/trips', { user: warehouse, routes: [{ path: '/cargo-trips', body: page([trip({ stage: 'in_sudan' })]) }] });

    expect(await screen.findByRole('link', { name: '000030' })).toHaveAttribute('href', '/trips/30');
    expect(within(screen.getByRole('table')).getByText(ar.trips.stage.in_sudan)).toBeInTheDocument();

    await user.selectOptions(screen.getByRole('combobox', { name: ar.common.status }), 'arrived');
    await waitFor(() => expect(calls.filter((call) => call.path === '/cargo-trips').at(-1)?.query.get('stage')).toBe('arrived'));
  });

  it('shows the new-trip button only to those who can load trips', async () => {
    renderApp('/trips', { user: trackerOnly, routes: [{ path: '/cargo-trips', body: page([]) }] });

    expect(await screen.findByText(ar.common.empty)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.trips.new })).not.toBeInTheDocument();
  });
});

describe('new trip', () => {
  it('offers trucks (not buses) and only normal shipments waiting in the warehouse', async () => {
    renderApp('/trips/new', { user: warehouse, routes: masterData });

    expect(await screen.findByRole('option', { name: /TRK 1/ })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: /BUS 1/ })).not.toBeInTheDocument();
    expect(await screen.findByRole('checkbox', { name: '000010' })).toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: '000012' })).not.toBeInTheDocument();
  });

  it('cannot be submitted without a truck, a driver, a shipment and a fingerprint', async () => {
    const user = userEvent.setup();
    renderApp('/trips/new', { user: warehouse, routes: masterData });

    const create = await screen.findByRole('button', { name: ar.trips.create });
    expect(create).toBeDisabled();

    await screen.findByRole('option', { name: /TRK 1/ });
    await screen.findByRole('option', { name: 'حسن إبراهيم' });
    await screen.findByRole('option', { name: 'أرقين' });
    await user.selectOptions(screen.getByLabelText(new RegExp(`^${ar.trips.vehicle}`)), '1');
    await user.selectOptions(screen.getByLabelText(new RegExp(`^${ar.trips.driver}`)), '2');
    await user.click(await screen.findByRole('checkbox', { name: '000010' }));
    expect(create).toBeDisabled();

    await user.upload(screen.getByLabelText(new RegExp(ar.trips.fingerprint)), new File(['x'], 'print.png', { type: 'image/png' }));
    expect(create).toBeEnabled();
  });

  it('uploads the fingerprint with the trip and opens the new trip', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/trips/new', {
      user: warehouse,
      routes: [
        ...masterData,
        { method: 'POST', path: '/cargo-trips', status: 201, body: { data: trip() } },
        { path: '/cargo-trips/30', body: { data: trip() } },
      ],
    });

    await screen.findByRole('option', { name: /TRK 1/ });
    await screen.findByRole('option', { name: 'حسن إبراهيم' });
    await screen.findByRole('option', { name: 'أرقين' });
    await user.selectOptions(screen.getByLabelText(new RegExp(`^${ar.trips.vehicle}`)), '1');
    await user.selectOptions(screen.getByLabelText(new RegExp(`^${ar.trips.driver}`)), '2');
    await user.selectOptions(screen.getByLabelText(new RegExp(`^${ar.trips.borderPoint}`)), '2');
    await user.click(await screen.findByRole('checkbox', { name: '000010' }));
    await user.type(screen.getByLabelText(/مدة التسليم/), '7');
    await user.type(screen.getByLabelText(new RegExp(ar.trips.dailyPenalty)), '50');
    await user.upload(screen.getByLabelText(new RegExp(ar.trips.fingerprint)), new File(['x'], 'print.png', { type: 'image/png' }));
    await user.click(screen.getByRole('button', { name: ar.trips.create }));

    expect(await screen.findByText(ar.trips.file('000030'))).toBeInTheDocument();
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({
      vehicle_id: '1',
      driver_id: '2',
      shipment_ids: ['10'],
      fingerprint: 'file:print.png',
      border_point_id: '2',
      delivery_days: '7',
      daily_penalty: '50',
      currency_id: '1',
    });
  });

  it('shows server problems next to the field they belong to', async () => {
    const user = userEvent.setup();
    renderApp('/trips/new', {
      user: warehouse,
      routes: [
        ...masterData,
        { method: 'POST', path: '/cargo-trips', status: 422, body: { message: 'x', errors: { vehicle_id: ['العربية على رحلة شغالة'], fingerprint: ['صورة البصمة مطلوبة'] } } },
      ],
    });

    await screen.findByRole('option', { name: /TRK 1/ });
    await screen.findByRole('option', { name: 'حسن إبراهيم' });
    await screen.findByRole('option', { name: 'أرقين' });
    await user.selectOptions(screen.getByLabelText(new RegExp(`^${ar.trips.vehicle}`)), '1');
    await user.selectOptions(screen.getByLabelText(new RegExp(`^${ar.trips.driver}`)), '2');
    await user.click(await screen.findByRole('checkbox', { name: '000010' }));
    await user.upload(screen.getByLabelText(new RegExp(ar.trips.fingerprint)), new File(['x'], 'print.png', { type: 'image/png' }));
    await user.click(screen.getByRole('button', { name: ar.trips.create }));

    expect(await screen.findByText('العربية على رحلة شغالة')).toBeInTheDocument();
    expect(screen.getByText('صورة البصمة مطلوبة')).toBeInTheDocument();
  });
});

describe('trip file', () => {
  const tab = { location: { href: '' }, close: vi.fn() };

  beforeEach(() => {
    tab.location.href = '';
    vi.spyOn(window, 'open').mockReturnValue(tab as unknown as Window);
    URL.createObjectURL = vi.fn(() => 'blob:pdf-url');
    URL.revokeObjectURL = vi.fn();
  });

  const fileRoutes = (data = trip(), extra: MockRoute[] = []): MockRoute[] => [...baseRoutes, { path: '/cargo-trips/30', body: { data } }, { path: '/cargo-trips', body: page([]) }, ...extra];

  it('shows the journey with reached stages, the undertaking and the fingerprint', async () => {
    renderApp('/trips/30', { user: warehouse, routes: fileRoutes(trip({ stage: 'at_aswan' })) });

    expect(await screen.findByText(ar.trips.file('000030'))).toBeInTheDocument();
    const items = screen.getAllByRole('listitem').filter((item) => item.className.includes('timeline__item'));
    expect(items.filter((item) => item.className.includes('--done'))).toHaveLength(3);
    expect(screen.getByRole('img', { name: ar.trips.fingerprintTitle })).toHaveAttribute('src', expect.stringContaining('/fingerprint'));
    expect(screen.getByText('000007')).toBeInTheDocument();
    expect(screen.getByText('أم درمان')).toBeInTheDocument();
  });

  it('offers only later stages and sends the chosen one with the notes', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/trips/30', {
      user: warehouse,
      routes: fileRoutes(trip({ stage: 'departed_cairo' }), [{ method: 'POST', path: '/cargo-trips/30/stage', body: { data: trip({ stage: 'in_sudan' }) } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.updateStage }));
    const dialog = screen.getByRole('dialog');
    const options = within(dialog).getAllByRole('option').map((option) => option.textContent);
    expect(options).not.toContain(ar.trips.stage.loaded);
    expect(options).not.toContain(ar.trips.stage.departed_cairo);
    expect(options[0]).toBe(ar.trips.stage.at_aswan);

    await user.selectOptions(within(dialog).getByLabelText(ar.trips.nextStage), 'in_sudan');
    await user.type(within(dialog).getByLabelText(new RegExp(ar.trips.notes)), 'عدّت الحدود');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/cargo-trips/30/stage')?.body).toEqual({ stage: 'in_sudan', notes: 'عدّت الحدود' });
  });

  it('allows cancelling and changing the fingerprint only before the trip leaves', async () => {
    renderApp('/trips/30', { user: warehouse, routes: fileRoutes(trip({ stage: 'loaded' })) });
    expect(await screen.findByRole('button', { name: ar.trips.cancelTrip })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: ar.trips.replaceFingerprint })).toBeInTheDocument();
  });

  it('hides cancelling once the trip has departed, and for users who only track', async () => {
    renderApp('/trips/30', { user: warehouse, routes: fileRoutes(trip({ stage: 'in_sudan' })) });
    await screen.findByText(ar.trips.file('000030'));
    expect(screen.queryByRole('button', { name: ar.trips.cancelTrip })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.trips.replaceFingerprint })).not.toBeInTheDocument();
  });

  it('lets a tracker update stages but not cancel', async () => {
    renderApp('/trips/30', { user: trackerOnly, routes: fileRoutes(trip({ stage: 'loaded' })) });

    expect(await screen.findByRole('button', { name: ar.trips.updateStage })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.trips.cancelTrip })).not.toBeInTheDocument();
  });

  it('cancels with a reason', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/trips/30', {
      user: warehouse,
      routes: fileRoutes(trip(), [{ method: 'POST', path: '/cargo-trips/30/cancel', body: { data: trip({ stage: 'cancelled' }) } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.cancelTrip }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(ar.trips.cancelHint)).toBeInTheDocument();
    await user.type(within(dialog).getByLabelText(ar.trips.cancelReason), 'العربية عطلت');
    await user.click(within(dialog).getByRole('button', { name: ar.trips.cancelTrip }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/cargo-trips/30/cancel')?.body).toEqual({ reason: 'العربية عطلت' });
  });

  it('replaces the fingerprint image', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/trips/30', {
      user: warehouse,
      routes: fileRoutes(trip(), [{ method: 'POST', path: '/cargo-trips/30/fingerprint', body: { data: trip() } }]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.replaceFingerprint }));
    const dialog = screen.getByRole('dialog');
    const save = within(dialog).getByRole('button', { name: ar.common.save });
    expect(save).toBeDisabled();
    await user.upload(within(dialog).getByLabelText(new RegExp(ar.trips.fingerprint)), new File(['x'], 'new.png', { type: 'image/png' }));
    await user.click(save);

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.path === '/cargo-trips/30/fingerprint')?.body).toEqual({ fingerprint: 'file:new.png' });
  });

  it('prints the manifest, the undertaking and a waybill for each shipment', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/trips/30', {
      user: warehouse,
      routes: fileRoutes(trip(), [
        { method: 'POST', path: '/documents', status: 201, body: { data: { id: 55 } } },
        { path: '/documents/55/download', body: { pdf: true } },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: ar.trips.printManifest }));
    await waitFor(() => expect(tab.location.href).toBe('blob:pdf-url'));
    await user.click(screen.getByRole('button', { name: ar.trips.printUndertaking }));
    await user.click(screen.getByRole('button', { name: `${ar.trips.printWaybill} 000011` }));

    await waitFor(() => expect(calls.filter((call) => call.path === '/documents')).toHaveLength(3));
    expect(calls.filter((call) => call.path === '/documents').map((call) => call.body)).toEqual([
      { type: 'manifest', subject_id: 30 },
      { type: 'driver_undertaking', subject_id: 7 },
      { type: 'waybill', subject_id: 11 },
    ]);
  });
});
