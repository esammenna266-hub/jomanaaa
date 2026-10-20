import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { Paginated, ShipmentSummary, Transfer } from '../../shared/api/types';

export function useTransfers(page: number, status: string) {
  return useQuery({
    queryKey: ['transfers', page, status],
    queryFn: () => api<Paginated<Transfer>>('/transfers', { query: { page, status, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

export function useTransfer(id: number) {
  return useQuery({
    queryKey: ['transfer', id],
    queryFn: async () => (await api<{ data: Transfer }>(`/transfers/${id}`)).data,
  });
}

/** Resolves a scanned barcode (the form's serial, or one of its shipments' codes) to the open transfer. */
export const lookupTransfer = async (code: string) => (await api<{ data: Transfer }>('/transfers/lookup', { query: { code } })).data;

/** Shipments waiting at a branch for a warehouse transfer (received at the branch, not yet sent). */
export function useReadyForTransfer(branchId: number | null, enabled: boolean) {
  return useQuery({
    queryKey: ['shipments', 'ready-for-transfer', branchId],
    queryFn: async () =>
      (await api<Paginated<ShipmentSummary>>('/shipments', { query: { status: 'at_branch', branch_id: branchId, per_page: 100 } })).data,
    enabled,
  });
}

export interface TransferPayload {
  from_branch_id?: number;
  shipments: { shipment_id: number; sent_count?: string }[];
  vehicle_text: string | null;
  driver_text: string | null;
  driver_phone: string | null;
}

export const createTransfer = (payload: TransferPayload) => api<{ data: Transfer }>('/transfers', { method: 'POST', body: payload });

export const receiveTransfer = (id: number, items: { shipment_id: number; received_count: string }[]) =>
  api<{ data: Transfer }>(`/transfers/${id}/receive`, { method: 'POST', body: { items } });
