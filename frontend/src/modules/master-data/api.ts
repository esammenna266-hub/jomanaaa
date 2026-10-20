import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { Currency, Customer, Driver, Lookup, Paginated, Vehicle } from '../../shared/api/types';

/** Active currencies, for labelling amounts everywhere. Reference data, so it is cached for the session. */
export function useCurrencies() {
  return useQuery({
    queryKey: ['currencies'],
    queryFn: async () => (await api<{ data: Currency[] }>('/currencies')).data,
    staleTime: Infinity,
  });
}

export function currencyName(currencies: Currency[] | undefined, id: number): string {
  return currencies?.find((currency) => currency.id === id)?.name_ar ?? '';
}

/** Active trucks (buses are not used for cargo trips), for the trip form. */
export function useTrucks(enabled = true) {
  return useQuery({
    queryKey: ['vehicles', 'trucks'],
    queryFn: async () =>
      (await api<Paginated<Vehicle>>('/vehicles', { query: { per_page: 100, active: 1 } })).data.filter((vehicle) => vehicle.category !== 'bus'),
    enabled,
  });
}

/** Active buses, for opening a bus trip. */
export function useBuses(enabled = true) {
  return useQuery({
    queryKey: ['vehicles', 'buses'],
    queryFn: async () =>
      (await api<Paginated<Vehicle>>('/vehicles', { query: { per_page: 100, active: 1 } })).data.filter((vehicle) => vehicle.category === 'bus'),
    enabled,
  });
}

export function useActiveDrivers(enabled = true) {
  return useQuery({
    queryKey: ['drivers', 'active'],
    queryFn: async () => (await api<Paginated<Driver>>('/drivers', { query: { per_page: 100, active: 1 } })).data,
    enabled,
  });
}

/** One of the small master-data lists: border points or clearing agents. */
export function useLookup(resource: 'border-points' | 'clearing-agents' | 'destinations', enabled = true) {
  return useQuery({
    queryKey: ['lookup', resource],
    queryFn: async () => (await api<Paginated<Lookup>>(`/${resource}`, { query: { per_page: 100, active: 1 } })).data,
    enabled,
  });
}

export interface CustomerPayload {
  name: string;
  phone: string;
  passport_no: string | null;
  country: 'EG' | 'SD' | null;
  active?: boolean;
}

export function useCustomers(page: number, q: string) {
  return useQuery({
    queryKey: ['customers', page, q],
    queryFn: () => api<Paginated<Customer>>('/customers', { query: { page, q, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

export const saveCustomer = (id: number | null, payload: CustomerPayload) =>
  id === null
    ? api<{ data: Customer }>('/customers', { method: 'POST', body: payload })
    : api<{ data: Customer }>(`/customers/${id}`, { method: 'PUT', body: payload });

export const setCustomerActive = (id: number, active: boolean) =>
  active ? api(`/customers/${id}`, { method: 'PUT', body: { active: true } }) : api(`/customers/${id}`, { method: 'DELETE' });
