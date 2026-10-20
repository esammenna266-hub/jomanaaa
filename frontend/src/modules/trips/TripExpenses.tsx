import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { ar } from '../../i18n/ar';
import type { CargoTrip, TripExpense } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { formatDateTime, formatMoney } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { ReasonDialog } from '../../shared/ui/ReasonDialog';
import { currencyName, useCurrencies } from '../master-data/api';
import { recordExpense, useExpenseTreasuries, useTripExpenses, voidExpense } from './api';

/** Money as whole cents, so shares are added up exactly (no floating point drift). */
function toCents(value: string): number | null {
  return /^\d{1,12}(\.\d{1,2})?$/.test(value.trim()) ? Math.round(Number.parseFloat(value) * 100) : null;
}

const fromCents = (cents: number) => (cents / 100).toFixed(2);

/** The same even split the server makes: the leftover cents go to the first shipments. */
export function splitEvenly(totalCents: number, shipmentIds: number[]): Record<number, number> {
  const base = Math.floor(totalCents / shipmentIds.length);
  const extra = totalCents % shipmentIds.length;

  return Object.fromEntries(shipmentIds.map((id, index) => [id, base + (index < extra ? 1 : 0)]));
}

export function TripExpenses({ trip }: { trip: CargoTrip }) {
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const expenses = useTripExpenses(trip.id, true);
  const [adding, setAdding] = useState(false);
  const [voiding, setVoiding] = useState<TripExpense | null>(null);

  const refresh = () => Promise.all([queryClient.invalidateQueries({ queryKey: ['trip-expenses', trip.id] }), queryClient.invalidateQueries({ queryKey: ['treasuries'] })]);
  const live = (expenses.data ?? []).filter((expense) => expense.voided_at === null);
  const totals = live.reduce<Record<number, number>>((sum, expense) => ({ ...sum, [expense.currency_id]: (sum[expense.currency_id] ?? 0) + (toCents(expense.amount) ?? 0) }), {});

  const columns: Column<TripExpense>[] = [
    { header: ar.trips.expenses.date, cell: (e) => formatDateTime(e.created_at) },
    { header: ar.trips.expenses.category, cell: (e) => <strong>{e.category}</strong> },
    { header: ar.trips.expenses.point, cell: (e) => e.point ?? '—' },
    {
      header: ar.trips.expenses.amount,
      cell: (e) => (
        <>
          <span className="ltr">{formatMoney(e.amount)}</span> {currencyName(currencies.data, e.currency_id)}
        </>
      ),
    },
    {
      header: ar.trips.expenses.distribution,
      cell: (e) => (
        <span className="ltr">
          {e.allocations.map((allocation) => `${allocation.shipment?.serial_no ?? allocation.shipment_id}: ${formatMoney(allocation.amount)}`).join(' · ')}
        </span>
      ),
    },
    {
      header: ar.common.actions,
      cell: (e) =>
        e.voided_at ? (
          <span className="badge badge--off">{ar.trips.expenses.voided}</span>
        ) : (
          <Button variant="ghost" onClick={() => setVoiding(e)}>
            {ar.trips.expenses.void}
          </Button>
        ),
    },
  ];

  return (
    <>
      <div className="page-header">
        <h2 className="section-title">{ar.trips.expenses.title}</h2>
        {trip.stage !== 'cancelled' && trip.shipments.length > 0 && <Button onClick={() => setAdding(true)}>{ar.trips.expenses.add}</Button>}
      </div>

      {expenses.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      <DataTable columns={columns} rows={expenses.data ?? []} rowKey={(e) => e.id} loading={expenses.isPending} />
      {Object.keys(totals).length > 0 && (
        <p className="muted">
          {ar.trips.expenses.totals}:{' '}
          {Object.entries(totals).map(([currencyId, cents]) => (
            <strong key={currencyId} className="expense-total">
              <span className="ltr">{formatMoney(fromCents(cents))}</span> {currencyName(currencies.data, Number(currencyId))}{' '}
            </strong>
          ))}
        </p>
      )}

      {adding && <ExpenseDialog trip={trip} onDone={refresh} onClose={() => setAdding(false)} />}
      {voiding && (
        <ReasonDialog
          title={ar.trips.expenses.voidTitle}
          label={ar.trips.expenses.voidReason}
          confirmLabel={ar.trips.expenses.void}
          intro={ar.trips.expenses.voidHint}
          danger
          onConfirm={async (reason) => {
            await voidExpense(voiding.id, reason);
            await refresh();
          }}
          onClose={() => setVoiding(null)}
        />
      )}
    </>
  );
}

function ExpenseDialog({ trip, onDone, onClose }: { trip: CargoTrip; onDone: () => Promise<unknown>; onClose: () => void }) {
  const treasuries = useExpenseTreasuries(true);
  const [category, setCategory] = useState('');
  const [point, setPoint] = useState('');
  const [treasuryId, setTreasuryId] = useState('');
  const [amount, setAmount] = useState('');
  const [mode, setMode] = useState<'equal' | 'manual'>('equal');
  const [shares, setShares] = useState<Record<number, string>>({});

  const shipmentIds = trip.shipments.map((shipment) => shipment.id);
  const totalCents = toCents(amount);
  const effectiveTreasury = treasuryId || (treasuries.data?.length === 1 ? String(treasuries.data[0]?.id) : '');
  const preview = totalCents !== null && totalCents > 0 ? splitEvenly(totalCents, shipmentIds) : null;
  const manualCents = shipmentIds.reduce((sum, id) => sum + (toCents(shares[id] ?? '') ?? 0), 0);
  const remainder = totalCents === null ? null : totalCents - manualCents;
  const balanced = mode === 'equal' || (remainder === 0 && totalCents !== null && totalCents > 0);

  const mutation = useMutation({
    mutationFn: () =>
      recordExpense(trip.id, {
        category: category.trim(),
        ...(point.trim() ? { point: point.trim() } : {}),
        treasury_id: Number(effectiveTreasury),
        amount: amount.trim(),
        ...(mode === 'manual' ? { allocations: shipmentIds.map((id) => ({ shipment_id: id, amount: (shares[id] ?? '').trim() || '0' })) } : {}),
      }),
    onSuccess: async () => {
      await onDone();
      onClose();
    },
  });

  const fields = ['category', 'point', 'treasury_id', 'amount'];

  return (
    <Modal title={ar.trips.expenses.addTitle} onClose={onClose}>
      <form
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          mutation.mutate();
        }}
        noValidate
      >
        {formError(mutation.error, fields) && <Notice tone="error">{formError(mutation.error, fields)}</Notice>}

        <TextField
          label={ar.trips.expenses.category}
          value={category}
          onChange={(event) => setCategory(event.target.value)}
          error={fieldError(mutation.error, 'category')}
          list="expense-categories"
          required
        />
        <datalist id="expense-categories">
          {ar.trips.expenses.categoryOptions.map((option) => (
            <option key={option} value={option} />
          ))}
        </datalist>
        <TextField label={ar.trips.expenses.point} value={point} onChange={(event) => setPoint(event.target.value)} error={fieldError(mutation.error, 'point')} optional />

        <SelectField label={ar.trips.expenses.treasury} value={effectiveTreasury} onChange={(event) => setTreasuryId(event.target.value)} error={fieldError(mutation.error, 'treasury_id')} required>
          <option value="">{ar.common.choose}</option>
          {treasuries.data?.map((treasury) => (
            <option key={treasury.id} value={treasury.id}>
              {ar.trips.expenses.payFrom(
                `${ar.shipments.treasuryMethod[treasury.method]} — ${treasury.currency?.name_ar} (${treasury.branch?.name})`,
                formatMoney(treasury.balance),
              )}
            </option>
          ))}
        </SelectField>
        <TextField label={ar.trips.expenses.amount} value={amount} onChange={(event) => setAmount(event.target.value)} error={fieldError(mutation.error, 'amount')} inputMode="decimal" ltr required />

        <SelectField label={ar.trips.expenses.split} value={mode} onChange={(event) => setMode(event.target.value as 'equal' | 'manual')}>
          <option value="equal">{ar.trips.expenses.splitEqual}</option>
          <option value="manual">{ar.trips.expenses.splitManual}</option>
        </SelectField>

        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th scope="col">{ar.shipments.columns.serial}</th>
                <th scope="col">{ar.shipments.columns.consignee}</th>
                <th scope="col">{mode === 'equal' ? ar.trips.expenses.preview : ar.trips.expenses.share}</th>
              </tr>
            </thead>
            <tbody>
              {trip.shipments.map((shipment) => (
                <tr key={shipment.id}>
                  <td className="ltr">{shipment.serial_no}</td>
                  <td>{shipment.consignee_name}</td>
                  <td>
                    {mode === 'equal' ? (
                      <span className="ltr">{preview ? fromCents(preview[shipment.id] ?? 0) : '—'}</span>
                    ) : (
                      <input
                        className="input ltr"
                        inputMode="decimal"
                        aria-label={`${ar.trips.expenses.share} ${shipment.serial_no}`}
                        value={shares[shipment.id] ?? ''}
                        onChange={(event) => setShares({ ...shares, [shipment.id]: event.target.value })}
                      />
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {mode === 'manual' && remainder !== null && (
          <p className={remainder === 0 ? 'text-ok' : 'text-danger'}>
            {remainder === 0 ? ar.trips.expenses.balanced : ar.trips.expenses.remainder(fromCents(remainder))}
          </p>
        )}

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={category.trim() === '' || effectiveTreasury === '' || totalCents === null || totalCents <= 0 || !balanced}>
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
