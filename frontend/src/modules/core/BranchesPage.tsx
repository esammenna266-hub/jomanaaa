import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { Branch } from '../../shared/api/types';
import { useDebounce } from '../../shared/hooks/useDebounce';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { StatusBadge } from '../../shared/ui/StatusBadge';
import { setBranchActive, useBranches } from './api';
import { BranchForm } from './BranchForm';

export function BranchesPage() {
  const { can } = useAuth();
  const canManage = can('branch.manage');
  const queryClient = useQueryClient();

  const [search, setSearch] = useState('');
  const q = useDebounce(search.trim());
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Branch | 'new' | null>(null);

  useEffect(() => setPage(1), [q]);

  const branches = useBranches(page, q);
  const toggle = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) => setBranchActive(id, active),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['branches'] }),
  });

  const columns: Column<Branch>[] = [
    { header: ar.branches.name, cell: (b) => <strong>{b.name}</strong> },
    { header: ar.branches.country, cell: (b) => ar.countries[b.country] },
    { header: ar.branches.phone, cell: (b) => <span className="ltr">{b.phone ?? '—'}</span> },
    { header: ar.branches.mainWarehouse, cell: (b) => (b.is_main_warehouse ? ar.common.yes : ar.common.no) },
    { header: ar.common.status, cell: (b) => <StatusBadge active={b.active} /> },
  ];

  if (canManage) {
    columns.push({
      header: ar.common.actions,
      cell: (b) => (
        <div className="row-actions">
          <Button variant="ghost" onClick={() => setEditing(b)}>
            {ar.common.edit}
          </Button>
          <Button
            variant="ghost"
            disabled={toggle.isPending}
            onClick={() => toggle.mutate({ id: b.id, active: !b.active })}
          >
            {b.active ? ar.common.deactivate : ar.common.activate}
          </Button>
        </div>
      ),
    });
  }

  return (
    <>
      <PageHeader title={ar.branches.title}>
        {canManage && <Button onClick={() => setEditing('new')}>{ar.branches.add}</Button>}
      </PageHeader>

      <div className="toolbar">
        <input
          className="input"
          type="search"
          aria-label={ar.common.search}
          placeholder={ar.branches.searchPlaceholder}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
      </div>

      {branches.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      {toggle.isError && <Notice tone="error">{toggle.error.message}</Notice>}

      <DataTable columns={columns} rows={branches.data?.data ?? []} rowKey={(b) => b.id} loading={branches.isPending} />
      <Pagination meta={branches.data?.meta} onPage={setPage} />

      {editing !== null && <BranchForm branch={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  );
}
