import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { PaymentRow, TreasuryOption } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { formatDateTime, formatMoney } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { SelectField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { StatusBadge } from '../../shared/ui/StatusBadge';
import { useAllBranches } from '../core/api';
import { useCurrencies } from '../master-data/api';
import { createTreasury, useMovements, useTreasuryList } from './api';

const METHODS = ['cash', 'instapay', 'vodafone_cash'] as const;
const label = (treasury: TreasuryOption) =>
  `${ar.shipments.treasuryMethod[treasury.method]} — ${treasury.currency?.name_ar ?? ''} (${treasury.branch?.name ?? ''})`;

export function TreasuriesPage() {
  const { can } = useAuth();
  const treasuries = useTreasuryList();
  const [adding, setAdding] = useState(false);
  const [viewing, setViewing] = useState<TreasuryOption | null>(null);

  const columns: Column<TreasuryOption>[] = [
    { header: ar.finance.treasuries.branch, cell: (t) => <strong>{t.branch?.name}</strong> },
    { header: ar.finance.treasuries.method, cell: (t) => ar.shipments.treasuryMethod[t.method] },
    { header: ar.finance.treasuries.currency, cell: (t) => t.currency?.name_ar },
    { header: ar.finance.treasuries.balance, cell: (t) => <strong className="ltr">{formatMoney(t.balance)}</strong> },
    { header: ar.common.status, cell: (t) => <StatusBadge active={t.active} /> },
    {
      header: ar.common.actions,
      cell: (t) => (
        <Button variant="ghost" onClick={() => setViewing(t)}>
          {ar.finance.treasuries.movements}
        </Button>
      ),
    },
  ];

  return (
    <>
      <PageHeader title={ar.finance.treasuries.title}>
        {can('treasury.manage') && <Button onClick={() => setAdding(true)}>{ar.finance.treasuries.add}</Button>}
      </PageHeader>

      {treasuries.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      <DataTable columns={columns} rows={treasuries.data ?? []} rowKey={(t) => t.id} loading={treasuries.isPending} />

      {adding && <TreasuryForm onClose={() => setAdding(false)} />}
      {viewing && <MovementsDialog treasury={viewing} onClose={() => setViewing(null)} />}
    </>
  );
}

function TreasuryForm({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient();
  const branches = useAllBranches();
  const currencies = useCurrencies();
  const [branchId, setBranchId] = useState('');
  const [currencyId, setCurrencyId] = useState('');
  const [method, setMethod] = useState<string>('cash');

  const mutation = useMutation({
    mutationFn: () => createTreasury({ branch_id: Number(branchId), currency_id: Number(currencyId), method }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['treasuries'] });
      onClose();
    },
  });

  const fields = ['branch_id', 'currency_id', 'method'];

  return (
    <Modal title={ar.finance.treasuries.addTitle} onClose={onClose}>
      <form
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          mutation.mutate();
        }}
        noValidate
      >
        {formError(mutation.error, fields) && <Notice tone="error">{formError(mutation.error, fields)}</Notice>}
        <Notice>{ar.finance.treasuries.fixedNote}</Notice>

        <SelectField label={ar.finance.treasuries.branch} value={branchId} onChange={(event) => setBranchId(event.target.value)} error={fieldError(mutation.error, 'branch_id')} required>
          <option value="">{ar.common.choose}</option>
          {branches.data?.map((branch) => (
            <option key={branch.id} value={branch.id}>
              {branch.name}
            </option>
          ))}
        </SelectField>
        <SelectField label={ar.finance.treasuries.method} value={method} onChange={(event) => setMethod(event.target.value)} error={fieldError(mutation.error, 'method')}>
          {METHODS.map((value) => (
            <option key={value} value={value}>
              {ar.shipments.treasuryMethod[value]}
            </option>
          ))}
        </SelectField>
        <SelectField label={ar.finance.treasuries.currency} value={currencyId} onChange={(event) => setCurrencyId(event.target.value)} error={fieldError(mutation.error, 'currency_id')} required>
          <option value="">{ar.common.choose}</option>
          {currencies.data?.map((currency) => (
            <option key={currency.id} value={currency.id}>
              {currency.name_ar}
            </option>
          ))}
        </SelectField>

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={branchId === '' || currencyId === ''}>
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

function MovementsDialog({ treasury, onClose }: { treasury: TreasuryOption; onClose: () => void }) {
  const [page, setPage] = useState(1);
  const movements = useMovements(treasury.id, page);

  const columns: Column<PaymentRow>[] = [
    { header: ar.finance.treasuries.date, cell: (p) => formatDateTime(p.created_at) },
    { header: ar.finance.treasuries.serial, cell: (p) => <span className="ltr">{p.serial_no}</span> },
    { header: ar.finance.treasuries.kind, cell: (p) => ar.finance.treasuries.kinds[p.kind] },
    {
      header: ar.finance.treasuries.amount,
      cell: (p) => (
        <span className={`ltr ${p.direction === 'in' ? 'text-ok' : 'text-danger'}`}>
          {p.direction === 'in' ? '+' : '−'}
          {formatMoney(p.amount)}
        </span>
      ),
    },
  ];

  return (
    <Modal title={ar.finance.treasuries.movementsTitle(label(treasury))} onClose={onClose}>
      {movements.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      <DataTable columns={columns} rows={movements.data?.data ?? []} rowKey={(p) => p.id} loading={movements.isPending} />
      <Pagination meta={movements.data?.meta} onPage={setPage} />
    </Modal>
  );
}
