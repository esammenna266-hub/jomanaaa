/** Latin digits everywhere (like phone numbers and serials), so figures can be compared at a glance. */
const LOCALE = 'ar-EG-u-nu-latn';

export function formatMoney(value: string | number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value));
}

export function formatDate(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }

  // Plain dates ("2026-11-05") must not shift with the viewer's time zone.
  const date = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00`) : new Date(value);

  return new Intl.DateTimeFormat(LOCALE, { dateStyle: 'medium', timeZone: 'Africa/Cairo' }).format(date);
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }

  return new Intl.DateTimeFormat(LOCALE, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Cairo' }).format(new Date(value));
}
