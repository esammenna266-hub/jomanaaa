import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { Remittance } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { formatDateTime, formatMoney } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { currencyName, useCurrencies } from '../master-data/api';
import { ReasonDialog } from '../../shared/ui/ReasonDialog';
import { useTreasuries } from '../shipping/api';
import { confirmRemittance, rejectRemittance, sendRemittance, useRemittanceDestinations, useRemittances } from './api';

const TONE = { sent: 'warn', confirmed: 'ok', rejected: 'danger' } as const;
const place = (treasury: Remittance['from_treasury']) =>
  treasury ? `${treasury.branch?.name ?? ''} — ${ar.shipments.treasuryMethod[treasury.method]}` : '—';

export function RemittancesPage() {
  const { can } = useAuth();
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [sending, setSending] = useState(false);
  const [rejecting, setRejecting] = useState<Remittance | null>(null);

  useEffect(() => setPage(1), [status]);

  const remittances = useRemittances(page, status);
  const refresh = () => Promise.all([queryClient.invalidateQueries({ queryKey: ['remittances'] }), queryClient.invalidateQueries({ queryKey: ['treasuries'] })]);
  const confirm = useMutation({ mutationFn: (id: number) => confirmRemittance(id), onSuccess: refresh });
  const canDecide = can('remittance.confirm');

  const columns: Column<Remittance>[] = [
    { header: ar.finance.remittances.columns.serial, cell: (r) => <span className="ltr">{r.serial_no}</span> },
    { header: ar.finance.remittances.columns.from, cell: (r) => place(r.from_treasury) },
    { header: ar.finance.remittances.columns.to, cell: (r) => place(r.to_treasury) },
    {
      header: ar.finance.remittances.columns.amount,
      cell: (r) => (
        <>
          <strong className="ltr">{formatMoney(r.amount)}</strong> {currencyName(currencies.data, r.currency_id)}
        </>
      ),
    },
    { header: ar.finance.remittances.columns.status, cell: (r) => <span className={`badge badge--${TONE[r.status]}`}>{ar.finance.remittances.status[r.status]}</span> },
    { header: ar.finance.remittances.columns.sentAt, cell: (r) => formatDateTime(r.sent_at) },
  ];

  if (canDecide) {
    columns.push({
      header: ar.common.actions,
      cell: (r) =>
        r.status === 'sent' ? (
          <div className="row-actions">
            <Button variant="ghost" disabled={confirm.isPending} onClick={() => confirm.mutate(r.id)}>
              {ar.finance.remittances.confirm}
            </Button>
            <Button variant="ghost" onClick={() => setRejecting(r)}>
              {ar.finance.remittances.reject}
            </Button>
          </div>
        ) : null,
    });
  }

  return (
    <>
      <PageHeader title={ar.finance.remittances.title}>
        {can('remittance.send') && <Button onClick={() => setSending(true)}>{ar.finance.remittances.new}</Button>}
      </PageHeader>

      <div className="toolbar">
        <select className="input" aria-label={ar.common.status} value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">{ar.finance.remittances.allStatuses}</option>
          {(['sent', 'confirmed', 'rejected'] as const).map((value) => (
            <option key={value} value={value}>
              {ar.finance.remittances.status[value]}
            </option>
          ))}
        </select>
      </div>

      {remittances.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      {confirm.isError && <Notice tone="error">{confirm.error.message}</Notice>}

      <DataTable columns={columns} rows={remittances.data?.data ?? []} rowKey={(r) => r.id} loading={remittances.isPending} />
      <Pagination meta={remittances.data?.meta} onPage={setPage} />

      {sending && <SendDialog onClose={() => setSending(false)} onDone={refresh} />}
      {rejecting && (
        <ReasonDialog
          title={ar.finance.remittances.rejectTitle}
          label={ar.finance.remittances.reason}
          confirmLabel={ar.finance.remittances.reject}
          danger
          onConfirm={async (reason) => {
            await rejectRemittance(rejecting.id, reason);
            await refresh();
          }}
          onClose={() => setRejecting(null)}
        />
      )}
    </>
  );
}

function SendDialog({ onClose, onDone }: { onClose: () => void; onDone: () => Promise<unknown> }) {
  const mine = useTreasuries(true);
  const destinations = useRemittanceDestinations(true);
  const [fromId, setFromId] = useState('');
  const [toId, setToId] = useState('');
  const [amount, setAmount] = useState('');

  const from = mine.data?.find((treasury) => String(treasury.id) === fromId);
  const targets = destinations.data?.filter((treasury) => from === undefined || treasury.currency_id === from.currency_id) ?? [];
  // When only one recipient fits the chosen currency there is nothing to decide.
  const effectiveTo = toId || (targets.length === 1 ? String(targets[0]?.id) : '');

  const mutation = useMutation({
    mutationFn: () => sendRemittance({ from_treasury_id: Number(fromId), to_treasury_id: Number(effectiveTo), amount: amount.trim() }),
    onSuccess: async () => {
      await onDone();
      onClose();
    },
  });

  const fields = ['from_treasury_id', 'to_treasury_id', 'amount'];

  return (
    <Modal title={ar.finance.remittances.newTitle} onClose={onClose}>
      <form
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          mutation.mutate();
        }}
        noValidate
      >
        {formError(mutation.error, fields) && <Notice tone="error">{formError(mutation.error, fields)}</Notice>}
        <Notice>{ar.finance.remittances.sendHint}</Notice>

        <SelectField label={ar.finance.remittances.from} value={fromId} onChange={(event) => { setFromId(event.target.value); setToId(''); }} error={fieldError(mutation.error, 'from_treasury_id')} required>
          <option value="">{ar.common.choose}</option>
          {mine.data?.map((treasury) => (
            <option key={treasury.id} value={treasury.id}>
              {ar.shipments.treasuryMethod[treasury.method]} — {treasury.currency?.name_ar} — {ar.finance.remittances.balanceOf(formatMoney(treasury.balance))}
            </option>
          ))}
        </SelectField>
        <SelectField label={ar.finance.remittances.to} value={effectiveTo} onChange={(event) => setToId(event.target.value)} error={fieldError(mutation.error, 'to_treasury_id')} required>
          <option value="">{ar.common.choose}</option>
          {targets.map((treasury) => (
            <option key={treasury.id} value={treasury.id}>
              {treasury.branch?.name} — {ar.shipments.treasuryMethod[treasury.method]} — {treasury.currency?.name_ar}
            </option>
          ))}
        </SelectField>
        <TextField label={ar.finance.remittances.amount} value={amount} onChange={(event) => setAmount(event.target.value)} error={fieldError(mutation.error, 'amount')} inputMode="decimal" ltr required />

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={fromId === '' || effectiveTo === '' || amount.trim() === ''}>
            {ar.finance.remittances.send}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
