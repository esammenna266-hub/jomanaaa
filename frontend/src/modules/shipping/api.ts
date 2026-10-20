import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { ItemUnit, Paginated, PassengerTrip, ShipmentFile, ShipmentOptions, ShipmentSummary, TreasuryOption } from '../../shared/api/types';

export function useShipments(page: number, q: string, status: string) {
  return useQuery({
    queryKey: ['shipments', page, q, status],
    queryFn: () => api<Paginated<ShipmentSummary>>('/shipments', { query: { page, q, status, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

export function useShipment(id: number) {
  return useQuery({
    queryKey: ['shipment', id],
    queryFn: async () => (await api<{ data: ShipmentFile }>(`/shipments/${id}`)).data,
  });
}

export function useShipmentOptions(enabled = true) {
  return useQuery({
    queryKey: ['shipment-options'],
    queryFn: async () => (await api<{ data: ShipmentOptions }>('/shipping/options')).data,
    enabled,
  });
}

/** Bus trips an express shipment can still ride (open, before the cutoff); the form also keeps the one it already has. */
export function useExpressBusTrips(enabled: boolean) {
  return useQuery({
    queryKey: ['passenger-trips', 'express-choices'],
    queryFn: async () => (await api<Paginated<PassengerTrip>>('/trips', { query: { kind: 'bus', sellable: 1, per_page: 100 } })).data,
    enabled,
  });
}

/** Treasuries the user may collect into, with their live balances. */
export function useTreasuries(enabled: boolean) {
  return useQuery({
    queryKey: ['treasuries', 'active'],
    queryFn: async () => (await api<Paginated<TreasuryOption>>('/treasuries', { query: { per_page: 100, active: 1 } })).data,
    enabled,
  });
}

export interface ItemPayload {
  name: string;
  qty: string;
  unit: ItemUnit;
  per_pack: string | null;
  weight_kg: string | null;
  notes: string | null;
}

export interface ShipmentPayload {
  customer_id: number;
  branch_id?: number;
  type: 'normal' | 'express';
  /** express shipments only: the bus trip whose express seat they take */
  bus_trip_id?: number | null;
  consignee_name: string;
  consignee_phone: string;
  destination_id: number | null;
  border_point_id: number | null;
  clearing_agent_id: number | null;
  proposed_category: string | null;
  proposed_body: string | null;
  proposed_tons: string | null;
  expected_date: string | null;
  currency_id: number | null;
  price: string | null;
  discount: string | null;
  agent_sale_price?: string | null;
  notes: string | null;
  items: ItemPayload[];
}

export const saveShipment = (id: number | null, payload: ShipmentPayload) =>
  id === null
    ? api<{ data: ShipmentFile }>('/shipments', { method: 'POST', body: payload })
    : api<{ data: ShipmentFile }>(`/shipments/${id}`, { method: 'PUT', body: payload });

const action = (id: number, name: string, body?: unknown) => api<{ data: ShipmentFile }>(`/shipments/${id}/${name}`, { method: 'POST', body });

export const submitShipment = (id: number) => action(id, 'submit');
export const receiveShipment = (id: number) => action(id, 'receive');
export const cancelShipment = (id: number, reason: string) => action(id, 'cancel', { reason });
export const deliverShipment = (id: number, receiverName: string) => action(id, 'deliver', { receiver_name: receiverName });
export const scheduleInstallments = (id: number, installments: { due_date: string; amount: string }[]) =>
  action(id, 'installments', { installments });

export const collectPayment = (payload: {
  shipment_id: number;
  treasury_id: number;
  amount: string;
  installment_id?: number;
  notes?: string;
}) => api('/payments', { method: 'POST', body: payload });

export const decideApproval = (id: number, decision: 'approve' | 'reject', reason?: string) =>
  api(`/approvals/${id}/decide`, { method: 'POST', body: { decision, reason } });
