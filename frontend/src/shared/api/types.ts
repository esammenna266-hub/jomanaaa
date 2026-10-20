/** Shapes returned by the Laravel API. */

export type Scope = 'all' | 'branch' | 'own';

export interface Role {
  id: number;
  code: string;
  name_ar: string;
}

export interface Branch {
  id: number;
  name: string;
  country: 'EG' | 'SD';
  timezone: string;
  is_main_warehouse: boolean;
  phone: string | null;
  address: string | null;
  active: boolean;
}

export interface Agent {
  id: number;
  name: string;
  phone: string | null;
  country: 'EG' | 'SD' | null;
  active: boolean;
}

export interface CurrentUser {
  id: number;
  name: string;
  username: string;
  active: boolean;
  must_change_password: boolean;
  role: Role | null;
  branch: Pick<Branch, 'id' | 'name'> | null;
  agent: Pick<Agent, 'id' | 'name'> | null;
  /** permission code -> how far it reaches */
  permissions: Record<string, Scope>;
}

export interface UserRecord {
  id: number;
  name: string;
  username: string;
  active: boolean;
  role_id: number;
  branch_id: number | null;
  agent_id: number | null;
  role: Role | null;
  branch: { id: number; name: string } | null;
  agent: { id: number; name: string } | null;
  last_login_at: string | null;
}

export interface Customer {
  id: number;
  name: string;
  phone: string;
  passport_no: string | null;
  country: 'EG' | 'SD' | null;
  whatsapp_verified_at: string | null;
  active: boolean;
}

export interface Currency {
  id: number;
  code: string;
  name_ar: string;
}

export interface Lookup {
  id: number;
  name: string;
}

export interface ShipmentOptions {
  border_points: Lookup[];
  destinations: Lookup[];
  clearing_agents: Lookup[];
}

export type ShipmentStatus =
  | 'draft'
  | 'pending_approval'
  | 'approved'
  | 'at_branch'
  | 'to_main_warehouse'
  | 'at_main_warehouse'
  | 'loaded'
  | 'in_transit'
  | 'arrived'
  | 'delivered'
  | 'cancelled'
  | 'lost'
  | 'damaged';

export type ItemUnit = 'piece' | 'carton' | 'sack' | 'barrel' | 'pack';

export interface ShipmentItem {
  id: number;
  name: string;
  qty: string;
  unit: ItemUnit;
  per_pack: string | null;
  weight_kg: string | null;
  notes: string | null;
}

/** Row in the shipments list. */
export interface ShipmentSummary {
  id: number;
  serial_no: string;
  tracking_code: string;
  type: 'normal' | 'express';
  status: ShipmentStatus;
  consignee_name: string;
  consignee_phone: string;
  currency_id: number;
  price: string;
  discount: string;
  expected_date: string | null;
  branch_id: number;
  customer: { name: string; phone: string } | null;
}

export interface PaymentRow {
  id: number;
  serial_no: string;
  direction: 'in' | 'out';
  kind: 'collection' | 'refund' | 'reversal' | 'expense' | 'remittance' | 'manual' | 'agent_settlement';
  amount: string;
  currency_id: number;
  treasury_id: number;
  notes: string | null;
  reversal_of_id: number | null;
  created_at: string;
}

export interface InstallmentRow {
  id: number;
  due_date: string;
  amount: string;
  currency_id: number;
  status: 'due' | 'paid' | 'overdue' | 'cancelled';
}

export interface ApprovalRow {
  id: number;
  type: 'price' | 'discount' | 'edit';
  status: 'pending' | 'approved' | 'rejected';
  value: string | null;
  reason: string | null;
}

export interface Balance {
  currency_id: number;
  total_due: string;
  paid: string;
  remaining: string;
  other_currencies: { currency_id: number; net: string }[];
}

/** Everything the shipment file screen shows. */
export interface ShipmentFile extends ShipmentSummary {
  customer_id: number;
  agent_id: number | null;
  agent_sale_price: string | null;
  notes: string | null;
  cancel_reason: string | null;
  delivered_to: string | null;
  delivered_at: string | null;
  proposed_category: 'jumbo' | 'single' | 'trailer' | 'bus' | null;
  proposed_body: 'closed' | 'open' | null;
  proposed_tons: string | null;
  destination_id: number;
  border_point_id: number | null;
  clearing_agent_id: number | null;
  bus_trip_id: number | null;
  bus_trip: Pick<PassengerTrip, 'id' | 'kind' | 'route' | 'depart_at' | 'status'> | null;
  branch: { id: number; name: string } | null;
  destination: Lookup | null;
  border_point: Lookup | null;
  clearing_agent: Lookup | null;
  items: ShipmentItem[];
  payments: PaymentRow[];
  installments: InstallmentRow[];
  approvals: ApprovalRow[];
  balance: Balance;
}

export interface TreasuryOption {
  id: number;
  branch_id: number;
  currency_id: number;
  method: 'cash' | 'instapay' | 'vodafone_cash';
  active: boolean;
  balance: string;
  currency: Currency | null;
  branch: { id: number; name: string } | null;
}

export interface Vehicle {
  id: number;
  plate_no: string;
  category: 'jumbo' | 'single' | 'trailer' | 'bus';
  body: 'closed' | 'open' | null;
  max_ton: string | null;
  seats?: number | null;
  active: boolean;
}

export interface Driver {
  id: number;
  name: string;
  phone: string | null;
  active: boolean;
}

export type TransferStatus = 'draft' | 'sent' | 'received' | 'received_with_diff';

export interface TransferItemRow {
  id: number;
  shipment_id: number;
  sent_count: string;
  received_count: string | null;
  /** received minus sent; null until the goods are counted at the warehouse */
  difference: string | null;
  shipment: {
    id: number;
    serial_no: string;
    tracking_code: string;
    consignee_name: string;
    customer: { id: number; name: string } | null;
  } | null;
}

export interface Transfer {
  id: number;
  serial_no: string;
  status: TransferStatus;
  from_branch: { id: number; name: string } | null;
  to_branch: { id: number; name: string } | null;
  vehicle_text: string | null;
  driver_text: string | null;
  driver_phone: string | null;
  sent_at: string | null;
  received_at: string | null;
  items_count?: number;
  items: TransferItemRow[];
  barcode: string;
  has_difference: boolean;
}

export type TripStage =
  | 'loaded'
  | 'departed_cairo'
  | 'at_aswan'
  | 'crossing_border'
  | 'in_sudan'
  | 'arrived'
  | 'completed'
  | 'cancelled';

export interface CargoTrip {
  id: number;
  serial_no: string;
  stage: TripStage;
  departed_at: string | null;
  arrived_at: string | null;
  vehicle: { id: number; plate_no: string; category?: string; body?: string | null; max_ton?: string | null } | null;
  driver: { id: number; name: string; phone?: string | null } | null;
  border_point: Lookup | null;
  clearing_agent: Lookup | null;
  shipments_count?: number;
  shipments: { id: number; serial_no: string; tracking_code: string; status: ShipmentStatus; consignee_name: string }[];
  undertaking: {
    id: number;
    serial_no: string;
    pickup_point: string | null;
    delivery_point: string | null;
    delivery_days: number | null;
    daily_penalty: string | null;
    currency_id: number | null;
    signed_at: string | null;
  } | null;
  events: { id: number; stage: string; happened_at: string; notes: string | null }[];
  fingerprint_url: string | null;
}

export interface TripExpense {
  id: number;
  category: string;
  point: string | null;
  amount: string;
  currency_id: number;
  treasury_id: number;
  voided_at: string | null;
  created_at: string;
  allocations: { id: number; shipment_id: number; amount: string; shipment: { id: number; serial_no: string; consignee_name: string } | null }[];
}

export interface TripDelay {
  id: number;
  days: number;
  cost: string;
  currency_id: number | null;
  responsible: 'customer' | 'driver' | 'company';
  notes: string | null;
  created_at: string;
}

export interface Remittance {
  id: number;
  serial_no: string;
  from_treasury_id: number;
  to_treasury_id: number;
  amount: string;
  currency_id: number;
  status: 'sent' | 'confirmed' | 'rejected';
  sent_at: string;
  confirmed_at: string | null;
  from_treasury: { id: number; method: TreasuryOption['method']; branch: { id: number; name: string } | null } | null;
  to_treasury: { id: number; method: TreasuryOption['method']; branch: { id: number; name: string } | null } | null;
}

export type PassengerTripKind = 'bus' | 'flight' | 'ship';
export type PassengerTripStatus = 'open' | 'express_locked' | 'departed' | 'completed' | 'cancelled';

/** A bus, flight or ship departure with a seat quota (not a cargo truck). */
export interface PassengerTrip {
  id: number;
  kind: PassengerTripKind;
  route: string;
  depart_at: string;
  checkin_at: string | null;
  status: PassengerTripStatus;
  vehicle: { id: number; plate_no: string; seats: number | null } | null;
  driver: { id: number; name: string; phone?: string | null } | null;
  capacity: number | null;
  passenger_seats: number;
  express_seats: number;
  express_cutoff_at: string | null;
  price: string;
  currency_id: number;
  booked_seats: number;
  /** passenger seats on sale: the quota plus express seats released after the cutoff */
  sellable_seats: number;
  free_seats: number;
  express_locked: boolean;
  express_used: number;
  express_free: number;
  express_released: number;
}

/** An express shipment riding a bus trip (rows of the trip file). */
export interface ExpressShipmentRow {
  id: number;
  serial_no: string;
  tracking_code: string;
  status: ShipmentStatus;
  consignee_name: string;
  consignee_phone: string;
  currency_id: number;
  price: string;
  discount: string;
  customer: { id: number; name: string } | null;
  destination: { id: number; name: string } | null;
  branch: { id: number; name: string } | null;
}

export type BookingStatus = 'reserved' | 'paid' | 'travelled' | 'cancelled';

export interface Booking {
  id: number;
  serial_no: string;
  trip_id: number;
  seat_no: number;
  passenger_name: string;
  passport_no: string | null;
  destination: string | null;
  customer_id: number | null;
  status: BookingStatus;
  sale_price: string;
  currency_id: number;
  cancel_reason: string | null;
  paid: string;
  trip?: Pick<PassengerTrip, 'id' | 'kind' | 'route' | 'depart_at' | 'status'> | null;
}

export type ServiceBookingStatus = 'reserved' | 'paid' | 'completed' | 'cancelled';

/** A hotel or car booking: free-form details and one price. */
export interface ServiceBooking {
  id: number;
  serial_no: string;
  type: 'hotel' | 'car';
  customer_id: number;
  details: string;
  start_date: string | null;
  end_date: string | null;
  price: string;
  currency_id: number;
  status: ServiceBookingStatus;
  cancel_reason: string | null;
  paid: string;
  customer?: { id: number; name: string; phone: string } | null;
}

/** One entry of the seat map. `booking` is present only for users who may see passenger details. */
export interface Seat {
  no: number;
  status: 'free' | 'booked';
  booking?: Pick<Booking, 'id' | 'serial_no' | 'passenger_name' | 'passport_no' | 'destination' | 'status' | 'sale_price' | 'paid'>;
  booked_by?: { branch: string | null; agent: string | null };
}

export type AgentService = 'bus' | 'flight' | 'ship' | 'express' | 'cargo';

/** What an external office pays the company for one service on one route. Internal, never printed. */
export interface AgentPrice {
  id: number;
  agent_id: number;
  service: AgentService;
  route: string;
  price: string;
  currency_id: number;
  active: boolean;
}

export interface AgentBalance {
  currency_id: number;
  due: string;
  paid: string;
  remaining: string;
}

export interface AgentSettlement {
  id: number;
  serial_no: string;
  direction: 'in' | 'out';
  kind: PaymentRow['kind'];
  amount: string;
  currency_id: number;
  treasury_id: number;
  notes: string | null;
  reversal_of_id: number | null;
  created_at: string;
}

export interface AgentAccount {
  agent: Agent;
  balances: AgentBalance[];
  settlements: AgentSettlement[];
}

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Paginated<T> {
  data: T[];
  meta: PageMeta;
}
