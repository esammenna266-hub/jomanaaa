import type { ShipmentStatus } from '../../shared/api/types';

/** Mirrors the backend: money can be collected once the price is approved, until the shipment is cancelled. */
export const COLLECTABLE: ShipmentStatus[] = [
  'approved',
  'at_branch',
  'to_main_warehouse',
  'at_main_warehouse',
  'loaded',
  'in_transit',
  'arrived',
  'delivered',
];

/** Cancelling is allowed up to the main warehouse, never once the goods are loaded. */
export const CANCELLABLE: ShipmentStatus[] = ['draft', 'pending_approval', 'approved', 'at_branch', 'to_main_warehouse', 'at_main_warehouse'];
