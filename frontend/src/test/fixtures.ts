import type { Booking, CargoTrip, PassengerTrip, Remittance, ShipmentFile, ShipmentSummary, Transfer, TreasuryOption } from '../shared/api/types';
import type { MockRoute } from './utils';

export const currencies = [
  { id: 1, code: 'EGP', name_ar: 'جنيه مصري' },
  { id: 2, code: 'USD', name_ar: 'دولار' },
];

export const baseRoutes: MockRoute[] = [{ path: '/currencies', body: { data: currencies } }];

export const summary = (overrides: Partial<ShipmentSummary> = {}): ShipmentSummary => ({
  id: 10,
  serial_no: '000010',
  tracking_code: 'ABCDE12345',
  type: 'normal',
  status: 'draft',
  consignee_name: 'عمر الطيب',
  consignee_phone: '+249912345678',
  currency_id: 1,
  price: '1000.00',
  discount: '100.00',
  expected_date: '2026-11-05',
  branch_id: 1,
  customer: { name: 'سعد محمود', phone: '+201012345678' },
  ...overrides,
});

export const shipmentFile = (overrides: Partial<ShipmentFile> = {}): ShipmentFile => ({
  ...summary(),
  customer_id: 5,
  agent_id: null,
  agent_sale_price: null,
  notes: null,
  cancel_reason: null,
  delivered_to: null,
  delivered_at: null,
  proposed_category: null,
  proposed_body: null,
  proposed_tons: null,
  destination_id: 1,
  border_point_id: null,
  clearing_agent_id: null,
  bus_trip_id: null,
  bus_trip: null,
  branch: { id: 1, name: 'فرع القاهرة' },
  destination: { id: 1, name: 'أم درمان' },
  border_point: null,
  clearing_agent: null,
  items: [{ id: 1, name: 'ملابس', qty: '10.00', unit: 'carton', per_pack: null, weight_kg: '100.00', notes: null }],
  payments: [],
  installments: [],
  approvals: [],
  balance: { currency_id: 1, total_due: '900.00', paid: '0.00', remaining: '900.00', other_currencies: [] },
  ...overrides,
});

export const transfer = (overrides: Partial<Transfer> = {}): Transfer => ({
  id: 20,
  serial_no: '000020',
  status: 'sent',
  from_branch: { id: 1, name: 'فرع القاهرة' },
  to_branch: { id: 9, name: 'مخزن فيصل' },
  vehicle_text: 'نقل 123',
  driver_text: 'حسن',
  driver_phone: '+201012345678',
  sent_at: '2026-10-18T09:00:00Z',
  received_at: null,
  items_count: 2,
  barcode: '000020',
  has_difference: false,
  items: [
    {
      id: 1, shipment_id: 10, sent_count: '10.00', received_count: null, difference: null,
      shipment: { id: 10, serial_no: '000010', tracking_code: 'ABCDE12345', consignee_name: 'عمر الطيب', customer: { id: 5, name: 'سعد محمود' } },
    },
    {
      id: 2, shipment_id: 11, sent_count: '4.00', received_count: null, difference: null,
      shipment: { id: 11, serial_no: '000011', tracking_code: 'ZZZZZ99999', consignee_name: 'ياسر عثمان', customer: { id: 5, name: 'سعد محمود' } },
    },
  ],
  ...overrides,
});

export const trip = (overrides: Partial<CargoTrip> = {}): CargoTrip => ({
  id: 30,
  serial_no: '000030',
  stage: 'loaded',
  departed_at: null,
  arrived_at: null,
  vehicle: { id: 1, plate_no: 'ن ص ع 1234', category: 'jumbo' },
  driver: { id: 2, name: 'حسن إبراهيم', phone: '+201098765432' },
  border_point: { id: 2, name: 'أرقين' },
  clearing_agent: null,
  shipments_count: 2,
  shipments: [
    { id: 10, serial_no: '000010', tracking_code: 'ABCDE12345', status: 'loaded', consignee_name: 'عمر الطيب' },
    { id: 11, serial_no: '000011', tracking_code: 'ZZZZZ99999', status: 'loaded', consignee_name: 'ياسر عثمان' },
  ],
  undertaking: { id: 7, serial_no: '000007', pickup_point: 'فيصل', delivery_point: 'أم درمان', delivery_days: 7, daily_penalty: '50.00', currency_id: 2, signed_at: '2026-10-18T09:00:00Z' },
  events: [{ id: 1, stage: 'loaded', happened_at: '2026-10-18T09:00:00Z', notes: null }],
  fingerprint_url: 'http://localhost/api/v1/cargo-trips/30/fingerprint?signature=x',
  ...overrides,
});

export const remittance = (overrides: Partial<Remittance> = {}): Remittance => ({
  id: 40,
  serial_no: '000040',
  from_treasury_id: 3,
  to_treasury_id: 8,
  amount: '400.00',
  currency_id: 1,
  status: 'sent',
  sent_at: '2026-10-18T09:00:00Z',
  confirmed_at: null,
  from_treasury: { id: 3, method: 'cash', branch: { id: 1, name: 'فرع القاهرة' } },
  to_treasury: { id: 8, method: 'cash', branch: { id: 9, name: 'مخزن فيصل' } },
  ...overrides,
});

export const treasury = (overrides: Partial<TreasuryOption> = {}): TreasuryOption => ({
  id: 3,
  branch_id: 1,
  currency_id: 1,
  method: 'cash',
  active: true,
  balance: '2500.00',
  currency: currencies[0]!,
  branch: { id: 1, name: 'فرع القاهرة' },
  ...overrides,
});

export const passengerTrip = (overrides: Partial<PassengerTrip> = {}): PassengerTrip => ({
  id: 40,
  kind: 'bus',
  route: 'القاهرة - أسوان',
  depart_at: '2099-11-01T06:00:00Z',
  checkin_at: null,
  status: 'open',
  vehicle: { id: 3, plate_no: 'BUS 1', seats: 4 },
  driver: { id: 2, name: 'حسن إبراهيم', phone: '+201098765432' },
  capacity: null,
  passenger_seats: 4,
  express_seats: 0,
  express_cutoff_at: null,
  price: '500.00',
  currency_id: 1,
  booked_seats: 1,
  sellable_seats: 4,
  free_seats: 3,
  express_locked: false,
  express_used: 0,
  express_free: 0,
  express_released: 0,
  ...overrides,
});

export const seatMap = (tripOverrides: Partial<PassengerTrip> = {}, withDetails = true) => ({
  data: {
    trip: passengerTrip(tripOverrides),
    seats: [
      {
        no: 1,
        status: 'booked',
        ...(withDetails
          ? { booking: { id: 60, serial_no: '000060', passenger_name: 'منى علي', passport_no: 'A123', destination: 'أسوان', status: 'paid', sale_price: '500.00', paid: '500.00' } }
          : {}),
      },
      { no: 2, status: 'free' },
      { no: 3, status: 'free' },
      { no: 4, status: 'free' },
    ],
  },
});

export const booking = (overrides: Partial<Booking> = {}): Booking => ({
  id: 60,
  serial_no: '000060',
  trip_id: 40,
  seat_no: 1,
  passenger_name: 'منى علي',
  passport_no: 'A123',
  destination: 'أسوان',
  customer_id: null,
  status: 'paid',
  sale_price: '500.00',
  currency_id: 1,
  cancel_reason: null,
  paid: '500.00',
  trip: { id: 40, kind: 'bus', route: 'القاهرة - أسوان', depart_at: '2099-11-01T06:00:00Z', status: 'open' },
  ...overrides,
});
