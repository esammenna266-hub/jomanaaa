import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { UserRecord } from '../../shared/api/types';
import { formatDateTime } from '../../shared/format';
import { useDebounce } from '../../shared/hooks/useDebounce';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { StatusBadge } from '../../shared/ui/StatusBadge';
import { resetUserPassword, setUserActive, useUsers } from './api';
import { UserForm } from './UserForm';

export function UsersPage() {
  const { can, user: me } = useAuth();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState('');
  const q = useDebounce(search.trim());
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<UserRecord | 'new' | null>(null);
  const [resetting, setResetting] = useState<UserRecord | null>(null);
  const [temporaryPassword, setTemporaryPassword] = useState<string | null>(null);

  useEffect(() => setPage(1), [q]);

  const users = useUsers(page, q);
  const toggle = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) => setUserActive(id, active),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['users'] }),
  });
  const reset = useMutation({
    mutationFn: (id: number) => resetUserPassword(id),
    onSuccess: (result) => {
      setResetting(null);
      setTemporaryPassword(result.temporary_password);
    },
  });

  const columns: Column<UserRecord>[] = [
    { header: ar.users.name, cell: (u) => <strong>{u.name}</strong> },
    { header: ar.users.username, cell: (u) => <span className="ltr">{u.username}</span> },
    { header: ar.users.role, cell: (u) => u.role?.name_ar ?? '—' },
    { header: `${ar.users.branch} / ${ar.users.agent}`, cell: (u) => u.branch?.name ?? u.agent?.name ?? '—' },
    { header: ar.users.lastLogin, cell: (u) => (u.last_login_at ? formatDateTime(u.last_login_at) : ar.users.never) },
    { header: ar.common.status, cell: (u) => <StatusBadge active={u.active} /> },
    {
      header: ar.common.actions,
      cell: (u) => (
        <div className="row-actions">
          <Button variant="ghost" onClick={() => setEditing(u)}>
            {ar.common.edit}
          </Button>
          <Button variant="ghost" onClick={() => setResetting(u)}>
            {ar.users.resetPassword}
          </Button>
          {u.id !== me?.id && (
            <Button variant="ghost" disabled={toggle.isPending} onClick={() => toggle.mutate({ id: u.id, active: !u.active })}>
              {u.active ? ar.common.deactivate : ar.common.activate}
            </Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader title={ar.users.title}>
        <Button onClick={() => setEditing('new')}>{ar.users.add}</Button>
      </PageHeader>

      <div className="toolbar">
        <input
          className="input"
          type="search"
          aria-label={ar.common.search}
          placeholder={ar.users.searchPlaceholder}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
      </div>

      {users.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      {toggle.isError && <Notice tone="error">{toggle.error.message}</Notice>}

      <DataTable columns={columns} rows={users.data?.data ?? []} rowKey={(u) => u.id} loading={users.isPending} />
      <Pagination meta={users.data?.meta} onPage={setPage} />

      {editing !== null && (
        <UserForm user={editing === 'new' ? null : editing} canListAgents={can('masterdata.view')} onClose={() => setEditing(null)} />
      )}

      {resetting && (
        <Modal title={ar.users.resetPassword} onClose={() => setResetting(null)}>
          {reset.isError && <Notice tone="error">{reset.error.message}</Notice>}
          <p>{ar.users.resetConfirm(resetting.name)}</p>
          <div className="form-actions">
            <Button variant="danger" loading={reset.isPending} onClick={() => reset.mutate(resetting.id)}>
              {ar.users.resetPassword}
            </Button>
            <Button variant="secondary" onClick={() => setResetting(null)}>
              {ar.common.cancel}
            </Button>
          </div>
        </Modal>
      )}

      {temporaryPassword && (
        <Modal title={ar.users.temporaryPassword} onClose={() => setTemporaryPassword(null)}>
          <div className="secret">{temporaryPassword}</div>
          <Notice>{ar.users.temporaryPasswordHint}</Notice>
          <div className="form-actions">
            <Button onClick={() => setTemporaryPassword(null)}>{ar.common.close}</Button>
          </div>
        </Modal>
      )}
    </>
  );
}
