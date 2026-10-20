import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { ar } from '../../i18n/ar';
import type { CargoTrip, TripDelay } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { formatDateTime, formatMoney } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { currencyName, useCurrencies } from '../master-data/api';
import { recordDelay, useTripDelays } from './api';

const RESPONSIBLE = ['customer', 'driver', 'company'] as const;

export function TripDelays({ trip }: { trip: CargoTrip }) {
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const delays = useTripDelays(trip.id, true);
  const [adding, setAdding] = useState(false);

  const columns: Column<TripDelay>[] = [
    { header: ar.trips.delays.date, cell: (d) => formatDateTime(d.created_at) },
    { header: ar.trips.delays.days, cell: (d) => <strong className="ltr">{d.days}</strong> },
    {
      header: ar.trips.delays.cost,
      cell: (d) =>
        Number(d.cost) > 0 ? (
          <>
            <span className="ltr">{formatMoney(d.cost)}</span> {d.currency_id ? currencyName(currencies.data, d.currency_id) : ''}
          </>
        ) : (
          '—'
        ),
    },
    { header: ar.trips.delays.responsible, cell: (d) => ar.trips.delays.responsibleOptions[d.responsible] },
    { header: ar.trips.delays.notes, cell: (d) => d.notes ?? '—' },
  ];

  return (
    <>
      <div className="page-header">
        <h2 className="section-title">{ar.trips.delays.title}</h2>
        {trip.stage !== 'cancelled' && <Button onClick={() => setAdding(true)}>{ar.trips.delays.add}</Button>}
      </div>

      {delays.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      <DataTable columns={columns} rows={delays.data ?? []} rowKey={(d) => d.id} loading={delays.isPending} />

      {adding && <DelayDialog trip={trip} onDone={() => queryClient.invalidateQueries({ queryKey: ['trip-delays', trip.id] })} onClose={() => setAdding(false)} />}
    </>
  );
}

function DelayDialog({ trip, onDone, onClose }: { trip: CargoTrip; onDone: () => Promise<unknown>; onClose: () => void }) {
  const currencies = useCurrencies();
  const [days, setDays] = useState('');
  const [cost, setCost] = useState('');
  const [currencyId, setCurrencyId] = useState('');
  const [responsible, setResponsible] = useState<string>('company');
  const [notes, setNotes] = useState('');

  const hasCost = cost.trim() !== '';
  const mutation = useMutation({
    mutationFn: () =>
      recordDelay(trip.id, {
        days: Number(days),
        ...(hasCost ? { cost: cost.trim(), currency_id: Number(currencyId || currencies.data?.[0]?.id) } : {}),
        responsible,
        ...(notes.trim() ? { notes: notes.trim() } : {}),
      }),
    onSuccess: async () => {
      await onDone();
      onClose();
    },
  });

  const fields = ['days', 'cost', 'currency_id', 'responsible', 'notes'];

  return (
    <Modal title={ar.trips.delays.addTitle} onClose={onClose}>
      <form
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          mutation.mutate();
        }}
        noValidate
      >
        {formError(mutation.error, fields) && <Notice tone="error">{formError(mutation.error, fields)}</Notice>}
        <Notice>{ar.trips.delays.note}</Notice>

        <TextField label={ar.trips.delays.days} value={days} onChange={(event) => setDays(event.target.value)} error={fieldError(mutation.error, 'days')} inputMode="numeric" ltr required />
        <TextField label={ar.trips.delays.cost} value={cost} onChange={(event) => setCost(event.target.value)} error={fieldError(mutation.error, 'cost')} inputMode="decimal" ltr optional />
        {hasCost && (
          <SelectField label={ar.trips.delays.currency} value={currencyId || String(currencies.data?.[0]?.id ?? '')} onChange={(event) => setCurrencyId(event.target.value)} error={fieldError(mutation.error, 'currency_id')}>
            {currencies.data?.map((currency) => (
              <option key={currency.id} value={currency.id}>
                {currency.name_ar}
              </option>
            ))}
          </SelectField>
        )}
        <SelectField label={ar.trips.delays.responsible} value={responsible} onChange={(event) => setResponsible(event.target.value)} error={fieldError(mutation.error, 'responsible')}>
          {RESPONSIBLE.map((value) => (
            <option key={value} value={value}>
              {ar.trips.delays.responsibleOptions[value]}
            </option>
          ))}
        </SelectField>
        <TextField label={ar.trips.delays.notes} value={notes} onChange={(event) => setNotes(event.target.value)} error={fieldError(mutation.error, 'notes')} optional />

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={days.trim() === ''}>
            {ar.common.save}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
