import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { Booking, ExpressShipmentRow, Paginated, PassengerTrip, PassengerTripKind, PassengerTripStatus, Seat } from '../../shared/api/types';

export interface TripFilters {
  kind: string;
  status: string;
  sellable: boolean;
}

export function usePassengerTrips(page: number, filters: TripFilters) {
  return useQuery({
    queryKey: ['passenger-trips', page, filters],
    queryFn: () =>
      api<Paginated<PassengerTrip>>('/trips', {
        query: { page, per_page: 15, kind: filters.kind, status: filters.status, ...(filters.sellable ? { sellable: 1 } : {}) },
      }),
    placeholderData: keepPreviousData,
  });
}

export interface SeatMap {
  trip: PassengerTrip;
  seats: Seat[];
}

export function useSeatMap(tripId: number) {
  return useQuery({
    queryKey: ['passenger-trip', tripId],
    queryFn: async () => (await api<{ data: SeatMap }>(`/trips/${tripId}/seats`)).data,
  });
}

/** Express shipments linked to the trip, limited to what the user may see. */
export function useTripExpressShipments(tripId: number, enabled: boolean) {
  return useQuery({
    queryKey: ['passenger-trip', tripId, 'express-shipments'],
    queryFn: async () => (await api<{ data: ExpressShipmentRow[] }>(`/trips/${tripId}/express-shipments`)).data,
    enabled,
  });
}

export interface TripPayload {
  kind: PassengerTripKind;
  route: string;
  depart_at: string;
  checkin_at?: string;
  vehicle_id?: number;
  driver_id?: number;
  passenger_seats: number;
  express_seats: number;
  express_cutoff_at?: string;
  price: string;
  currency_id: number;
}

export const createPassengerTrip = (payload: TripPayload) => api<{ data: PassengerTrip }>('/trips', { method: 'POST', body: payload });

export const tripAction = (id: number, action: 'depart' | 'complete' | 'cancel') =>
  api<{ data: PassengerTrip }>(`/trips/${id}/${action}`, { method: 'POST' });

export interface BookingPayload {
  trip_id: number;
  seat_no: number;
  passenger_name: string;
  passport_no?: string;
  destination?: string;
  customer_id?: number;
  sale_price?: string;
  /** counter staff only: external offices book on account */
  treasury_id?: number;
}

export const bookSeat = (payload: BookingPayload) => api<{ data: Booking }>('/bookings', { method: 'POST', body: payload });

export const cancelBooking = (id: number, reason: string) => api<{ data: Booking }>(`/bookings/${id}/cancel`, { method: 'POST', body: { reason } });

export function useBookings(page: number, q: string, status: string) {
  return useQuery({
    queryKey: ['bookings', page, q, status],
    queryFn: () => api<Paginated<Booking>>('/bookings', { query: { page, q, status, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

export const isSellable = (status: PassengerTripStatus) => status === 'open' || status === 'express_locked';
