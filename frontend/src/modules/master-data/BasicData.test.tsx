import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { fakeUser, page, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const manager = fakeUser({ 'masterdata.view': 'all', 'masterdata.manage': 'all' });
const viewer = fakeUser({ 'masterdata.view': 'all' });

const destination = { id: 1, name: 'أم درمان', country: 'SD', active: true };
const bus = { id: 3, plate_no: 'أ ب ج 4521', category: 'bus', body: null, max_ton: null, seats: 49, ownership: 'owned', active: true };

const routes = (extra: MockRoute[] = []): MockRoute[] => [
  { path: '/destinations', body: page([destination, { id: 2, name: 'القاهرة', country: 'EG', active: false }]) },
  { path: '/border-points', body: page([{ id: 1, name: 'أرقين', active: true }]) },
  { path: '/clearing-agents', body: page([]) },
  { path: '/vehicles', body: page([bus]) },
  { path: '/drivers', body: page([{ id: 4, name: 'حسن إبراهيم', phone: '+201098765432', license_no: 'L-1', is_international: true, active: true }]) },
  ...extra,
];

describe('basic data', () => {
  it('opens on the destinations and switches between the five lists', async () => {
    const user = userEvent.setup();
    renderApp('/basic-data', { user: manager, routes: routes() });

    expect(await screen.findByText('أم درمان')).toBeInTheDocument();
    expect(screen.getByText('القاهرة')).toBeInTheDocument();

    await user.click(screen.getByRole('tab', { name: ar.basicData.tabs.vehicles }));
    expect(await screen.findByText('أ ب ج 4521')).toBeInTheDocument();
    expect(screen.getByText(ar.basicData.seatsCount('49'))).toBeInTheDocument();

    await user.click(screen.getByRole('tab', { name: ar.basicData.tabs.drivers }));
    expect(await screen.findByText('حسن إبراهيم')).toBeInTheDocument();
  });

  it('adds a destination with its country', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/basic-data', { user: manager, routes: routes([{ method: 'POST', path: '/destinations', status: 201, body: { data: destination } }]) });

    await user.click(await screen.findByRole('button', { name: ar.basicData.addTitle(ar.basicData.single.destinations) }));
    const dialog = screen.getByRole('dialog');
    const save = within(dialog).getByRole('button', { name: ar.common.save });
    expect(save).toBeDisabled();

    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.basicData.fields.name}`)), 'القضارف');
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(`^${ar.basicData.fields.country}`)), 'SD');
    await user.click(save);

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/destinations')?.body).toEqual({ name: 'القضارف', country: 'SD' });
  });

  it('asks for seats on a bus and for the body and tons on a truck', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/basic-data', { user: manager, routes: routes([{ method: 'POST', path: '/vehicles', status: 201, body: { data: bus } }]) });

    await user.click(await screen.findByRole('tab', { name: ar.basicData.tabs.vehicles }));
    await user.click(await screen.findByRole('button', { name: ar.basicData.addTitle(ar.basicData.single.vehicles) }));
    const dialog = screen.getByRole('dialog');

    // A truck: body and tons, no seats.
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.basicData.fields.plate_no}`)), 'ن ص ع 1234');
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(`^${ar.basicData.fields.category}`)), 'jumbo');
    expect(within(dialog).getByLabelText(new RegExp(ar.basicData.fields.body))).toBeInTheDocument();
    expect(within(dialog).queryByLabelText(new RegExp(ar.basicData.fields.seats))).not.toBeInTheDocument();

    // A bus: seats are required, body and tons go away.
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(`^${ar.basicData.fields.category}`)), 'bus');
    expect(within(dialog).queryByLabelText(new RegExp(ar.basicData.fields.body))).not.toBeInTheDocument();
    const save = within(dialog).getByRole('button', { name: ar.common.save });
    expect(save).toBeDisabled();
    await user.type(within(dialog).getByLabelText(new RegExp(`^${ar.basicData.fields.seats}`)), '49');
    await waitFor(() => expect(save).toBeEnabled());
    await user.click(save);

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST' && call.path === '/vehicles')?.body).toMatchObject({
      plate_no: 'ن ص ع 1234',
      category: 'bus',
      seats: '49',
      body: null,
      max_ton: null,
    });
  });

  it('stops and restarts a destination without deleting it', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/basic-data', {
      user: manager,
      routes: routes([
        { method: 'DELETE', path: '/destinations/1', body: { message: 'تم الإيقاف' } },
        { method: 'PUT', path: '/destinations/2', body: { data: { id: 2, active: true } } },
      ]),
    });

    const row = (await screen.findByText('أم درمان')).closest('tr')!;
    await user.click(within(row).getByRole('button', { name: ar.common.deactivate }));
    await waitFor(() => expect(calls.some((call) => call.method === 'DELETE' && call.path === '/destinations/1')).toBe(true));

    const stopped = screen.getByText('القاهرة').closest('tr')!;
    await user.click(within(stopped).getByRole('button', { name: ar.common.activate }));
    await waitFor(() => expect(calls.find((call) => call.method === 'PUT' && call.path === '/destinations/2')?.body).toEqual({ active: true }));
  });

  it('shows the lists read-only to users who cannot manage master data', async () => {
    renderApp('/basic-data', { user: viewer, routes: routes() });

    expect(await screen.findByText('أم درمان')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.basicData.addTitle(ar.basicData.single.destinations) })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.common.edit })).not.toBeInTheDocument();
  });

  it('keeps users without master-data access out', async () => {
    renderApp('/basic-data', { user: fakeUser({ 'shipment.view': 'all' }), routes: [] });

    expect(await screen.findByText(ar.home.intro)).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: ar.basicData.tabs.destinations })).not.toBeInTheDocument();
  });
});
