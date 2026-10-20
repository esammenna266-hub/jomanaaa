import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { Customer } from '../../shared/api/types';
import { useDebounce } from '../../shared/hooks/useDebounce';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { StatusBadge } from '../../shared/ui/StatusBadge';
import { setCustomerActive, useCustomers } from './api';
import { CustomerForm } from './CustomerForm';

export function CustomersPage() {
  const { can } = useAuth();
  const canManage = can('customer.create');
  const queryClient = useQueryClient();

  const [search, setSearch] = useState('');
  const q = useDebounce(search.trim());
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Customer | 'new' | null>(null);

  useEffect(() => setPage(1), [q]);

  const customers = useCustomers(page, q);
  const toggle = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) => setCustomerActive(id, active),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['customers'] }),
  });

  const columns: Column<Customer>[] = [
    { header: ar.customers.name, cell: (c) => <strong>{c.name}</strong> },
    { header: ar.customers.phone, cell: (c) => <span className="ltr">{c.phone}</span> },
    { header: ar.customers.passport, cell: (c) => <span className="ltr">{c.passport_no ?? '—'}</span> },
    { header: ar.customers.country, cell: (c) => (c.country ? ar.countries[c.country] : '—') },
    { header: ar.customers.whatsapp, cell: (c) => (c.whatsapp_verified_at ? ar.customers.verified : ar.customers.notVerified) },
    { header: ar.common.status, cell: (c) => <StatusBadge active={c.active} /> },
  ];

  if (canManage) {
    columns.push({
      header: ar.common.actions,
      cell: (c) => (
        <div className="row-actions">
          <Button variant="ghost" onClick={() => setEditing(c)}>
            {ar.common.edit}
          </Button>
          <Button
            variant="ghost"
            disabled={toggle.isPending}
            onClick={() => toggle.mutate({ id: c.id, active: !c.active })}
          >
            {c.active ? ar.common.deactivate : ar.common.activate}
          </Button>
        </div>
      ),
    });
  }

  return (
    <>
      <PageHeader title={ar.customers.title}>
        {canManage && <Button onClick={() => setEditing('new')}>{ar.customers.add}</Button>}
      </PageHeader>

      <div className="toolbar">
        <input
          className="input"
          type="search"
          aria-label={ar.common.search}
          placeholder={ar.customers.searchPlaceholder}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          autoFocus
        />
      </div>

      {customers.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      {toggle.isError && <Notice tone="error">{toggle.error.message}</Notice>}

      <DataTable columns={columns} rows={customers.data?.data ?? []} rowKey={(c) => c.id} loading={customers.isPending} />
      <Pagination meta={customers.data?.meta} onPage={setPage} />

      {editing !== null && <CustomerForm customer={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  );
}
