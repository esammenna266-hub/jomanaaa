import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { Paginated, PaymentRow, Remittance, TreasuryOption } from '../../shared/api/types';

/** Every treasury the user can see, including stopped ones, with balances. */
export function useTreasuryList() {
  return useQuery({
    queryKey: ['treasuries', 'list'],
    queryFn: async () => (await api<Paginated<TreasuryOption>>('/treasuries', { query: { per_page: 100 } })).data,
  });
}

export function useMovements(treasuryId: number, page: number) {
  return useQuery({
    queryKey: ['payments', treasuryId, page],
    queryFn: () => api<Paginated<PaymentRow>>('/payments', { query: { treasury_id: treasuryId, page, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

export const createTreasury = (payload: { branch_id: number; currency_id: number; method: string }) =>
  api<{ data: TreasuryOption }>('/treasuries', { method: 'POST', body: payload });

export function useRemittances(page: number, status: string) {
  return useQuery({
    queryKey: ['remittances', page, status],
    queryFn: () => api<Paginated<Remittance>>('/remittances', { query: { page, status, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

/** Treasuries a branch can send cash to (the main warehouse's). */
export function useRemittanceDestinations(enabled: boolean) {
  return useQuery({
    queryKey: ['remittance-destinations'],
    queryFn: async () => (await api<{ data: TreasuryOption[] }>('/remittances/destinations')).data,
    enabled,
  });
}

export const sendRemittance = (payload: { from_treasury_id: number; to_treasury_id: number; amount: string }) =>
  api<{ data: Remittance }>('/remittances', { method: 'POST', body: payload });

export const confirmRemittance = (id: number) => api(`/remittances/${id}/confirm`, { method: 'POST' });

export const rejectRemittance = (id: number, reason: string) => api(`/remittances/${id}/reject`, { method: 'POST', body: { reason } });
