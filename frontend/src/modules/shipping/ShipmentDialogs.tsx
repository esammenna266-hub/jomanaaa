import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { ar } from '../../i18n/ar';
import type { ShipmentFile } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { formatMoney } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { collectPayment, scheduleInstallments, useTreasuries } from './api';

export function CollectDialog({ shipment, onDone, onClose }: { shipment: ShipmentFile; onDone: () => Promise<unknown>; onClose: () => void }) {
  const queryClient = useQueryClient();
  const treasuries = useTreasuries(true);
  const [treasuryId, setTreasuryId] = useState('');
  const [amount, setAmount] = useState('');
  const [installmentId, setInstallmentId] = useState('');
  const [notes, setNotes] = useState('');

  const open = shipment.installments.filter((installment) => installment.status === 'due' || installment.status === 'overdue');
  const sameCurrency = treasuries.data?.filter((treasury) => treasury.currency_id === shipment.currency_id) ?? [];
  // A lone treasury in the shipment's currency is the obvious choice.
  const effectiveTreasury = treasuryId || (sameCurrency.length === 1 ? String(sameCurrency[0]?.id) : '');
  const chosen = treasuries.data?.find((treasury) => String(treasury.id) === effectiveTreasury);
  const crossCurrency = chosen !== undefined && chosen.currency_id !== shipment.currency_id;

  const mutation = useMutation({
    mutationFn: () =>
      collectPayment({
        shipment_id: shipment.id,
        treasury_id: Number(effectiveTreasury),
        amount: amount.trim(),
        ...(installmentId ? { installment_id: Number(installmentId) } : {}),
        ...(notes.trim() ? { notes: notes.trim() } : {}),
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['treasuries'] });
      await onDone();
      onClose();
    },
  });

  const fields = ['treasury_id', 'amount', 'installment_id', 'notes'];

  return (
    <Modal title={ar.shipments.collect} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          mutation.mutate();
        }}
        noValidate
      >
        {formError(mutation.error, [...fields, 'shipment_id']) && <Notice tone="error">{formError(mutation.error, [...fields, 'shipment_id'])}</Notice>}
        {fieldError(mutation.error, 'shipment_id') && <Notice tone="error">{fieldError(mutation.error, 'shipment_id')}</Notice>}

        <SelectField label={ar.shipments.treasury} value={effectiveTreasury} onChange={(event) => setTreasuryId(event.target.value)} error={fieldError(mutation.error, 'treasury_id')} required>
          <option value="">{ar.common.choose}</option>
          {treasuries.data?.map((treasury) => (
            <option key={treasury.id} value={treasury.id}>
              {ar.shipments.treasuryMethod[treasury.method]} — {treasury.currency?.name_ar} ({treasury.branch?.name}) — {ar.shipments.treasuryBalance} {formatMoney(treasury.balance)}
            </option>
          ))}
        </SelectField>
        {crossCurrency && <Notice>{ar.shipments.differentCurrency}</Notice>}

        {open.length > 0 && (
          <SelectField
            label={ar.shipments.installment}
            value={installmentId}
            onChange={(event) => {
              setInstallmentId(event.target.value);
              const picked = open.find((installment) => String(installment.id) === event.target.value);
              if (picked) {
                setAmount(String(Number(picked.amount)));
              }
            }}
            error={fieldError(mutation.error, 'installment_id')}
            optional
          >
            <option value="">{ar.shipments.installmentNone}</option>
            {open.map((installment) => (
              <option key={installment.id} value={installment.id}>
                {installment.due_date} — {formatMoney(installment.amount)}
              </option>
            ))}
          </SelectField>
        )}

        <TextField
          label={ar.shipments.amount}
          value={amount}
          onChange={(event) => setAmount(event.target.value)}
          error={fieldError(mutation.error, 'amount')}
          hint={ar.shipments.collectAmountHint(formatMoney(shipment.balance.remaining))}
          inputMode="decimal"
          ltr
          required
        />
        <TextField label={ar.shipments.notes} value={notes} onChange={(event) => setNotes(event.target.value)} error={fieldError(mutation.error, 'notes')} optional />

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={effectiveTreasury === '' || amount.trim() === ''}>
            {ar.shipments.collect}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

interface InstallmentRowState {
  key: number;
  due_date: string;
  amount: string;
}

export function InstallmentsDialog({ shipment, onDone, onClose }: { shipment: ShipmentFile; onDone: () => Promise<unknown>; onClose: () => void }) {
  const [rows, setRows] = useState<InstallmentRowState[]>([{ key: 1, due_date: '', amount: '' }]);

  const mutation = useMutation({
    mutationFn: () => scheduleInstallments(shipment.id, rows.map((row) => ({ due_date: row.due_date, amount: row.amount.trim() }))),
    onSuccess: async () => {
      await onDone();
      onClose();
    },
  });

  const update = (key: number, patch: Partial<InstallmentRowState>) => setRows(rows.map((row) => (row.key === key ? { ...row, ...patch } : row)));

  return (
    <Modal title={ar.shipments.scheduleInstallments} onClose={onClose}>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          mutation.mutate();
        }}
        noValidate
      >
        {formError(mutation.error, () => false) && <Notice tone="error">{formError(mutation.error, () => false)}</Notice>}
        <Notice>{ar.shipments.collectAmountHint(formatMoney(shipment.balance.remaining))}</Notice>

        {rows.map((row, index) => (
          <div className="installment-row" key={row.key}>
            <TextField
              label={`${ar.shipments.dueDate} ${index + 1}`}
              type="date"
              value={row.due_date}
              onChange={(event) => update(row.key, { due_date: event.target.value })}
              error={fieldError(mutation.error, `installments.${index}.due_date`)}
              ltr
              required
            />
            <TextField
              label={`${ar.shipments.amount} ${index + 1}`}
              value={row.amount}
              onChange={(event) => update(row.key, { amount: event.target.value })}
              error={fieldError(mutation.error, `installments.${index}.amount`)}
              inputMode="decimal"
              ltr
              required
            />
          </div>
        ))}

        <Button variant="ghost" onClick={() => setRows([...rows, { key: Math.max(...rows.map((row) => row.key)) + 1, due_date: '', amount: '' }])}>
          + {ar.shipments.addInstallment}
        </Button>

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending}>
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
