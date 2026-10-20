import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { Agent, Branch, Paginated } from '../../shared/api/types';

export interface BranchPayload {
  name: string;
  country: 'EG' | 'SD';
  phone: string | null;
  address: string | null;
  is_main_warehouse: boolean;
  active?: boolean;
}

export function useBranches(page: number, q: string) {
  return useQuery({
    queryKey: ['branches', page, q],
    queryFn: () => api<Paginated<Branch>>('/branches', { query: { page, q, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

/** Every branch, for dropdowns. */
export function useAllBranches() {
  return useQuery({
    queryKey: ['branches', 'all'],
    queryFn: async () => (await api<Paginated<Branch>>('/branches', { query: { per_page: 100, active: 1 } })).data,
  });
}

/** Every external office, for dropdowns (needs masterdata.view). */
export function useAllAgents(enabled: boolean) {
  return useQuery({
    queryKey: ['agents', 'all'],
    queryFn: async () => (await api<Paginated<Agent>>('/agents', { query: { per_page: 100, active: 1 } })).data,
    enabled,
  });
}

export const saveBranch = (id: number | null, payload: BranchPayload) =>
  id === null
    ? api<{ data: Branch }>('/branches', { method: 'POST', body: payload })
    : api<{ data: Branch }>(`/branches/${id}`, { method: 'PUT', body: payload });

export const setBranchActive = (id: number, active: boolean) =>
  active ? api(`/branches/${id}`, { method: 'PUT', body: { active: true } }) : api(`/branches/${id}`, { method: 'DELETE' });
