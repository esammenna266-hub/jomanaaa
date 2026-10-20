import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { Paginated, ServiceBooking } from '../../shared/api/types';

export function useServiceBookings(page: number, q: string, type: string, status: string) {
  return useQuery({
    queryKey: ['service-bookings', page, q, type, status],
    queryFn: () => api<Paginated<ServiceBooking>>('/service-bookings', { query: { page, q, type, status, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

export interface ServiceBookingPayload {
  type: 'hotel' | 'car';
  customer_id: number;
  details: string;
  start_date?: string;
  end_date?: string;
  price: string;
  currency_id: number;
  /** counter staff only: leave out to keep the booking reserved until it is collected */
  treasury_id?: number;
}

export const createServiceBooking = (payload: ServiceBookingPayload) =>
  api<{ data: ServiceBooking }>('/service-bookings', { method: 'POST', body: payload });

export const collectServiceBooking = (id: number, treasuryId: number) =>
  api<{ data: ServiceBooking }>(`/service-bookings/${id}/collect`, { method: 'POST', body: { treasury_id: treasuryId } });

export const completeServiceBooking = (id: number) => api<{ data: ServiceBooking }>(`/service-bookings/${id}/complete`, { method: 'POST' });

export const cancelServiceBooking = (id: number, reason: string) =>
  api<{ data: ServiceBooking }>(`/service-bookings/${id}/cancel`, { method: 'POST', body: { reason } });
