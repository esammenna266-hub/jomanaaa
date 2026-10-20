import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { baseRoutes, passengerTrip, shipmentFile } from '../../test/fixtures';
import { fakeUser, page, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const staff = fakeUser({
  'shipment.view': 'branch',
  'shipment.create': 'branch',
  'customer.view': 'branch',
  'customer.create': 'all',
});

const customer = { id: 5, name: 'سعد محمود', phone: '+201012345678', passport_no: null, country: 'EG', whatsapp_verified_at: null, active: true };

const formRoutes = (extra: MockRoute[] = []): MockRoute[] => [
  ...baseRoutes,
  {
    path: '/shipping/options',
    body: {
      data: {
        border_points: [{ id: 2, name: 'أرقين' }],
        destinations: [{ id: 1, name: 'أم درمان' }],
        clearing_agents: [],
      },
    },
  },
  { path: '/customers', body: page([customer]) },
  { path: '/shipments/10', body: { data: shipmentFile() } },
  { path: '/shipments', body: page([]) },
  ...extra,
];

async function fillValidForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(await screen.findByLabelText(ar.shipments.customer), '0101');
  await user.click(await screen.findByRole('button', { name: /سعد محمود/ }));
  await user.type(screen.getByLabelText(ar.shipments.consigneeName), 'عمر الطيب');
  await user.type(screen.getByLabelText(new RegExp(ar.shipments.consigneePhone)), '0912345678');
  await screen.findByRole('option', { name: 'أم درمان' });
  await user.selectOptions(screen.getByLabelText(new RegExp(ar.shipments.destination)), '1');
  await user.type(screen.getByLabelText(`${ar.shipments.itemName} 1`), 'ملابس');
  await user.type(screen.getByLabelText(`${ar.shipments.itemQty} 1`), '10');
  await user.type(screen.getByLabelText(new RegExp(`^${ar.shipments.price}`)), '1000');
}

describe('new shipment form', () => {
  it('saves a draft with the picked customer and the items, then opens the file', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/new', {
      user: staff,
      routes: formRoutes([{ method: 'POST', path: '/shipments', status: 201, body: { data: shipmentFile() } }]),
    });

    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: ar.shipments.saveDraft }));

    expect(await screen.findByText(ar.shipments.file('000010'))).toBeInTheDocument();
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({
      customer_id: 5,
      type: 'normal',
      consignee_name: 'عمر الطيب',
      consignee_phone: '0912345678',
      destination_id: 1,
      border_point_id: null,
      clearing_agent_id: null,
      proposed_category: null,
      proposed_body: null,
      proposed_tons: null,
      expected_date: null,
      currency_id: 1,
      price: '1000',
      discount: null,
      notes: null,
      items: [{ name: 'ملابس', qty: '10', unit: 'carton', per_pack: null, weight_kg: null, notes: null }],
    });
    expect(calls.some((call) => call.path.endsWith('/submit'))).toBe(false);
  });

  it('saves and sends for approval in one go', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/new', {
      user: staff,
      routes: formRoutes([
        { method: 'POST', path: '/shipments', status: 201, body: { data: shipmentFile() } },
        { method: 'POST', path: '/shipments/10/submit', body: { data: shipmentFile({ status: 'pending_approval' }) } },
      ]),
    });

    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: ar.shipments.saveAndSubmit }));

    await screen.findByText(ar.shipments.file('000010'));
    expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(
      expect.arrayContaining(['POST /shipments', 'POST /shipments/10/submit']),
    );
  });

  it('keeps the draft and says so when the approval request is refused', async () => {
    const user = userEvent.setup();
    renderApp('/shipments/new', {
      user: staff,
      routes: formRoutes([
        { method: 'POST', path: '/shipments', status: 201, body: { data: shipmentFile() } },
        { method: 'POST', path: '/shipments/10/submit', status: 422, body: { message: 'x', errors: { price: ['حدد السعر قبل الإرسال للاعتماد'] } } },
      ]),
    });

    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: ar.shipments.saveAndSubmit }));

    expect(await screen.findByText(ar.shipments.noteFromSubmit('x'))).toBeInTheDocument();
  });

  it('asks for a customer before anything is sent', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/new', { user: staff, routes: formRoutes() });

    await user.click(await screen.findByRole('button', { name: ar.shipments.saveDraft }));

    expect(await screen.findByText(ar.shipments.customerRequired)).toBeInTheDocument();
    expect(calls.some((call) => call.method === 'POST')).toBe(false);
  });

  it('shows server errors next to the item row they belong to', async () => {
    const user = userEvent.setup();
    renderApp('/shipments/new', {
      user: staff,
      routes: formRoutes([
        {
          method: 'POST',
          path: '/shipments',
          status: 422,
          body: { message: 'x', errors: { 'items.0.qty': ['العدد لازم يكون أكبر من صفر'], destination_id: ['اختار الوجهة'] } },
        },
      ]),
    });

    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: ar.shipments.saveDraft }));

    const quantity = await screen.findByLabelText(`${ar.shipments.itemQty} 1`);
    expect(quantity).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('العدد لازم يكون أكبر من صفر')).toBeInTheDocument();
    expect(screen.getByText('اختار الوجهة')).toBeInTheDocument();
  });

  it('lets items be added and removed', async () => {
    const user = userEvent.setup();
    renderApp('/shipments/new', { user: staff, routes: formRoutes() });

    await user.click(await screen.findByRole('button', { name: new RegExp(ar.shipments.addItem) }));
    expect(screen.getByLabelText(`${ar.shipments.itemName} 2`)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: `${ar.shipments.removeItem} 2` }));
    expect(screen.queryByLabelText(`${ar.shipments.itemName} 2`)).not.toBeInTheDocument();
  });

  it('shows the sale price field only to external offices', async () => {
    const office = fakeUser(
      { 'shipment.view': 'own', 'shipment.create': 'own', 'customer.view': 'own' },
      { branch: null, agent: { id: 4, name: 'مكتب الخرطوم' } },
    );
    renderApp('/shipments/new', { user: office, routes: formRoutes() });

    expect(await screen.findByLabelText(new RegExp(ar.shipments.agentSalePrice))).toBeInTheDocument();
  });

  it('can create a customer without leaving the form', async () => {
    const user = userEvent.setup();
    renderApp('/shipments/new', {
      user: staff,
      routes: formRoutes([
        { method: 'POST', path: '/customers', status: 201, body: { data: { ...customer, id: 9, name: 'عميل جديد' } } },
      ]),
    });

    await user.click(await screen.findByRole('button', { name: new RegExp(ar.shipments.customerNew) }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(ar.customers.name), 'عميل جديد');
    await user.type(within(dialog).getByLabelText(ar.customers.phone), '01011112222');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    expect(await screen.findByText('عميل جديد')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: ar.shipments.customerChange })).toBeInTheDocument();
  });
});

describe('edit draft', () => {
  it('opens a draft with its values and sends them back with a PUT', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/10/edit', {
      user: staff,
      routes: formRoutes([{ method: 'PUT', path: '/shipments/10', body: { data: shipmentFile() } }]),
    });

    expect(await screen.findByDisplayValue('عمر الطيب')).toBeInTheDocument();
    expect(screen.getByDisplayValue('ملابس')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: ar.shipments.saveDraft }));

    await screen.findByText(ar.shipments.file('000010'));
    expect(calls.find((call) => call.method === 'PUT')?.body.customer_id).toBe(5);
  });

  it('sends anything that is no longer a draft back to its file', async () => {
    renderApp('/shipments/10/edit', {
      user: staff,
      routes: formRoutes([{ path: '/shipments/10', body: { data: shipmentFile({ status: 'approved' }) } }]),
    });

    expect(await screen.findByText(ar.shipments.file('000010'))).toBeInTheDocument();
  });
});

describe('express shipment bus trip', () => {
  const openTrip = passengerTrip({ id: 40, express_seats: 3, express_used: 1, express_free: 2 });
  const fullTrip = passengerTrip({ id: 41, route: 'القاهرة - الأقصر', express_seats: 1, express_used: 1, express_free: 0 });
  const tripsRoute: MockRoute = { path: '/trips', body: page([openTrip, fullTrip]) };

  it('offers a trip with free express seats only for express shipments and sends the choice', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/new', {
      user: staff,
      routes: formRoutes([tripsRoute, { method: 'POST', path: '/shipments', status: 201, body: { data: shipmentFile() } }]),
    });

    await fillValidForm(user);
    expect(screen.queryByLabelText(new RegExp(ar.shipments.busTrip))).not.toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText(ar.shipments.kind), 'express');
    await screen.findByRole('option', { name: /القاهرة - أسوان/ });
    expect(screen.queryByRole('option', { name: /القاهرة - الأقصر/ })).not.toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText(new RegExp(ar.shipments.busTrip)), '40');
    await user.click(screen.getByRole('button', { name: ar.shipments.saveDraft }));

    await screen.findByText(ar.shipments.file('000010'));
    expect(calls.find((call) => call.method === 'POST')?.body).toMatchObject({ type: 'express', bus_trip_id: 40 });
    expect(calls.find((call) => call.path === '/trips')?.query.get('kind')).toBe('bus');
  });

  it('keeps the trip an existing draft already holds even when it is full, and shows the server refusal', async () => {
    const user = userEvent.setup();
    renderApp('/shipments/10/edit', {
      user: staff,
      routes: formRoutes([
        tripsRoute,
        { path: '/shipments/10', body: { data: shipmentFile({ type: 'express', bus_trip_id: 41 }) } },
        { method: 'PUT', path: '/shipments/10', status: 422, body: { message: 'x', errors: { bus_trip_id: ['كراسي الشحن في الرحلة دي كملت'] } } },
      ]),
    });

    expect(await screen.findByRole('option', { name: /القاهرة - الأقصر/ })).toBeInTheDocument();
    expect(screen.getByLabelText(new RegExp(ar.shipments.busTrip))).toHaveValue('41');

    await user.click(screen.getByRole('button', { name: ar.shipments.saveDraft }));
    expect(await screen.findByText('كراسي الشحن في الرحلة دي كملت')).toBeInTheDocument();
  });

  it('sends no trip for a normal shipment', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/shipments/new', {
      user: staff,
      routes: formRoutes([tripsRoute, { method: 'POST', path: '/shipments', status: 201, body: { data: shipmentFile() } }]),
    });

    await fillValidForm(user);
    await user.click(screen.getByRole('button', { name: ar.shipments.saveDraft }));

    await screen.findByText(ar.shipments.file('000010'));
    expect(calls.find((call) => call.method === 'POST')?.body).not.toHaveProperty('bus_trip_id');
  });
});
