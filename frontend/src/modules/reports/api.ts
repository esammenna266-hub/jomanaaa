import { useQuery } from '@tanstack/react-query';
import { api, apiBlob } from '../../shared/api/client';

export interface ReportTable {
  title: string;
  columns: { key: string; label: string }[];
  rows: Record<string, string | number>[];
}

export interface Dashboard {
  treasury_totals: { currency: string; amount: string }[];
  collected_today: { currency: string; amount: string }[];
  open_shipments: Record<string, number>;
  /** null when the user sees only their branch */
  pending_approvals: number | null;
  overdue_installments: number;
  pending_remittances: number;
  cargo_trips_on_the_road: number | null;
  passenger_trips_open: number;
  agents_owing: number | null;
}

export interface ReportDefinition {
  slug: string;
  /** whether the report is about a period (and so has a date range) */
  dated: boolean;
}

export const REPORTS: ReportDefinition[] = [
  { slug: 'treasury-balances', dated: false },
  { slug: 'branch-activity', dated: true },
  { slug: 'cancellations', dated: true },
  { slug: 'refunds', dated: true },
  { slug: 'staff-movements', dated: true },
  { slug: 'debts', dated: false },
  { slug: 'agents', dated: false },
];

const queryString = (from: string, to: string, extra: Record<string, string> = {}) => {
  const params = new URLSearchParams();
  if (from) params.set('from', from);
  if (to) params.set('to', to);
  Object.entries(extra).forEach(([key, value]) => params.set(key, value));
  const text = params.toString();

  return text ? `?${text}` : '';
};

export function useReport(slug: string, from: string, to: string) {
  return useQuery({
    queryKey: ['report', slug, from, to],
    queryFn: async () => (await api<{ data: ReportTable }>(`/reports/${slug}${queryString(from, to)}`)).data,
  });
}

export function useDashboard(enabled: boolean) {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => (await api<{ data: Dashboard }>('/reports/dashboard')).data,
    enabled,
  });
}

/** Downloads the report as a CSV file (Excel opens it) using the session token. */
export async function downloadReportCsv(slug: string, from: string, to: string): Promise<void> {
  const blob = await apiBlob(`/reports/${slug}${queryString(from, to, { format: 'csv' })}`);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${slug}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
