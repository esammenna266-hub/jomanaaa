import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { Paginated, Role, UserRecord } from '../../shared/api/types';

export interface UserPayload {
  name: string;
  username: string;
  password?: string;
  role_id: number;
  branch_id: number | null;
  agent_id: number | null;
  active?: boolean;
}

export function useUsers(page: number, q: string) {
  return useQuery({
    queryKey: ['users', page, q],
    queryFn: () => api<Paginated<UserRecord>>('/users', { query: { page, q, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

export function useRoles() {
  return useQuery({
    queryKey: ['roles'],
    queryFn: async () => (await api<{ data: Role[] }>('/roles')).data,
    staleTime: Infinity,
  });
}

export const saveUser = (id: number | null, payload: UserPayload) =>
  id === null
    ? api<{ data: UserRecord }>('/users', { method: 'POST', body: payload })
    : api<{ data: UserRecord }>(`/users/${id}`, { method: 'PUT', body: payload });

export const setUserActive = (id: number, active: boolean) =>
  active ? api(`/users/${id}`, { method: 'PUT', body: { active: true } }) : api(`/users/${id}`, { method: 'DELETE' });

export const resetUserPassword = (id: number) =>
  api<{ temporary_password: string }>(`/users/${id}/reset-password`, { method: 'POST' });
