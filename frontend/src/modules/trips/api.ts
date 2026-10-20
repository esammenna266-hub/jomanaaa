import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { CargoTrip, Paginated, ShipmentSummary, TreasuryOption, TripDelay, TripExpense, TripStage } from '../../shared/api/types';

/** Order of the journey; a trip only moves forward through these. */
export const TRIP_STAGES: TripStage[] = ['loaded', 'departed_cairo', 'at_aswan', 'crossing_border', 'in_sudan', 'arrived', 'completed'];

export function useTrips(page: number, stage: string) {
  return useQuery({
    queryKey: ['trips', page, stage],
    queryFn: () => api<Paginated<CargoTrip>>('/cargo-trips', { query: { page, stage, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

export function useTrip(id: number) {
  return useQuery({
    queryKey: ['trip', id],
    queryFn: async () => (await api<{ data: CargoTrip }>(`/cargo-trips/${id}`)).data,
  });
}

/** Normal shipments waiting in the main warehouse, ready to be loaded on a truck. */
export function useLoadableShipments() {
  return useQuery({
    queryKey: ['shipments', 'loadable'],
    queryFn: async () =>
      (await api<Paginated<ShipmentSummary>>('/shipments', { query: { status: 'at_main_warehouse', per_page: 100 } })).data.filter(
        (shipment) => shipment.type === 'normal',
      ),
  });
}

export function useTripExpenses(tripId: number, enabled: boolean) {
  return useQuery({
    queryKey: ['trip-expenses', tripId],
    queryFn: async () => (await api<{ data: TripExpense[] }>(`/cargo-trips/${tripId}/expenses`)).data,
    enabled,
  });
}

/** Treasuries an expense can be paid from (the warehouse's, plus any the user can already see). */
export function useExpenseTreasuries(enabled: boolean) {
  return useQuery({
    queryKey: ['expense-treasuries'],
    queryFn: async () => (await api<{ data: TreasuryOption[] }>('/expenses/treasuries')).data,
    enabled,
  });
}

export interface ExpensePayload {
  category: string;
  point?: string;
  treasury_id: number;
  amount: string;
  /** omitted = split evenly across the trip's shipments */
  allocations?: { shipment_id: number; amount: string }[];
}

export const recordExpense = (tripId: number, payload: ExpensePayload) =>
  api<{ data: TripExpense }>(`/cargo-trips/${tripId}/expenses`, { method: 'POST', body: payload });

export const voidExpense = (id: number, reason: string) => api(`/expenses/${id}/void`, { method: 'POST', body: { reason } });

export function useTripDelays(tripId: number, enabled: boolean) {
  return useQuery({
    queryKey: ['trip-delays', tripId],
    queryFn: async () => (await api<{ data: TripDelay[] }>(`/cargo-trips/${tripId}/delays`)).data,
    enabled,
  });
}

export const recordDelay = (
  tripId: number,
  payload: { days: number; cost?: string; currency_id?: number; responsible: string; notes?: string },
) => api<{ data: TripDelay }>(`/cargo-trips/${tripId}/delays`, { method: 'POST', body: payload });

/** Multipart: trip fields, undertaking terms, shipment ids and the fingerprint image. */
export const createTrip = (form: FormData) => api<{ data: CargoTrip }>('/cargo-trips', { method: 'POST', body: form });

export const advanceTrip = (id: number, stage: TripStage, notes: string | null) =>
  api<{ data: CargoTrip }>(`/cargo-trips/${id}/stage`, { method: 'POST', body: { stage, ...(notes ? { notes } : {}) } });

export const cancelTrip = (id: number, reason: string) => api<{ data: CargoTrip }>(`/cargo-trips/${id}/cancel`, { method: 'POST', body: { reason } });

export const replaceFingerprint = (id: number, file: File) => {
  const form = new FormData();
  form.append('fingerprint', file);

  return api<{ data: CargoTrip }>(`/cargo-trips/${id}/fingerprint`, { method: 'POST', body: form });
};
