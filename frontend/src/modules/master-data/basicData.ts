import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { Paginated } from '../../shared/api/types';

/** The simple lists the company maintains itself: where shipments go, border points, clearing agents, vehicles and drivers. */
export type BasicDataKey = 'destinations' | 'border-points' | 'clearing-agents' | 'vehicles' | 'drivers';

export interface BasicRecord {
  id: number;
  active: boolean;
  [field: string]: unknown;
}

export type FieldValues = Record<string, string | boolean>;

export interface FieldDef {
  name: string;
  kind: 'text' | 'number' | 'select' | 'checkbox';
  options?: { value: string; label: string }[];
  required?: boolean | ((values: FieldValues) => boolean);
  /** Shown only when this returns true (for example seats on a bus). */
  visible?: (values: FieldValues) => boolean;
  ltr?: boolean;
}

export const BASIC_DATA: Record<BasicDataKey, { fields: FieldDef[] }> = {
  destinations: {
    fields: [
      { name: 'name', kind: 'text', required: true },
      {
        name: 'country',
        kind: 'select',
        options: [
          { value: 'SD', label: 'السودان' },
          { value: 'EG', label: 'مصر' },
        ],
      },
    ],
  },
  'border-points': { fields: [{ name: 'name', kind: 'text', required: true }] },
  'clearing-agents': {
    fields: [
      { name: 'name', kind: 'text', required: true },
      { name: 'phone', kind: 'text', ltr: true },
    ],
  },
  vehicles: {
    fields: [
      { name: 'plate_no', kind: 'text', required: true, ltr: true },
      {
        name: 'category',
        kind: 'select',
        required: true,
        options: [
          { value: 'jumbo', label: 'جامبو' },
          { value: 'single', label: 'سنجل' },
          { value: 'trailer', label: 'تريلا' },
          { value: 'bus', label: 'أتوبيس' },
        ],
      },
      {
        name: 'body',
        kind: 'select',
        visible: (values) => values.category !== 'bus',
        options: [
          { value: 'closed', label: 'مقفولة' },
          { value: 'open', label: 'مفتوحة' },
        ],
      },
      { name: 'max_ton', kind: 'number', ltr: true, visible: (values) => values.category !== 'bus' },
      { name: 'seats', kind: 'number', ltr: true, required: (values) => values.category === 'bus', visible: (values) => values.category === 'bus' },
      { name: 'owner_name', kind: 'text' },
      {
        name: 'ownership',
        kind: 'select',
        options: [
          { value: 'owned', label: 'ملك الشركة' },
          { value: 'rented', label: 'إيجار' },
        ],
      },
    ],
  },
  drivers: {
    fields: [
      { name: 'name', kind: 'text', required: true },
      { name: 'phone', kind: 'text', ltr: true },
      { name: 'license_no', kind: 'text', ltr: true },
      {
        name: 'id_type',
        kind: 'select',
        options: [
          { value: 'national_id', label: 'بطاقة رقم قومي' },
          { value: 'passport', label: 'جواز سفر' },
          { value: 'other', label: 'أخرى' },
        ],
      },
      { name: 'id_no', kind: 'text', ltr: true },
      { name: 'is_international', kind: 'checkbox' },
    ],
  },
};

export function useBasicData(key: BasicDataKey, page: number, q: string) {
  return useQuery({
    queryKey: ['basic-data', key, page, q],
    queryFn: () => api<Paginated<BasicRecord>>(`/${key}`, { query: { page, q, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

/** Turns the form into what the API takes: blanks become null, hidden fields are cleared, numbers stay as typed. */
export function toPayload(key: BasicDataKey, values: FieldValues): Record<string, unknown> {
  const payload: Record<string, unknown> = {};

  for (const field of BASIC_DATA[key].fields) {
    const raw = values[field.name];

    if (field.visible && !field.visible(values)) {
      payload[field.name] = null;
    } else if (field.kind === 'checkbox') {
      payload[field.name] = raw === true;
    } else {
      const text = typeof raw === 'string' ? raw.trim() : '';
      payload[field.name] = text === '' ? null : text;
    }
  }

  return payload;
}

export const saveBasicRecord = (key: BasicDataKey, id: number | null, payload: Record<string, unknown>) =>
  id === null ? api(`/${key}`, { method: 'POST', body: payload }) : api(`/${key}/${id}`, { method: 'PUT', body: payload });

export const setBasicRecordActive = (key: BasicDataKey, id: number, active: boolean) =>
  active ? api(`/${key}/${id}`, { method: 'PUT', body: { active: true } }) : api(`/${key}/${id}`, { method: 'DELETE' });
