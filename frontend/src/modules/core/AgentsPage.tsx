import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { Agent } from '../../shared/api/types';
import { useDebounce } from '../../shared/hooks/useDebounce';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { StatusBadge } from '../../shared/ui/StatusBadge';
import { setAgentActive, useAgents } from './agentsApi';
import { AgentForm } from './AgentForm';

export function AgentsPage() {
  const { can } = useAuth();
  const canManage = can('masterdata.manage');
  const queryClient = useQueryClient();

  const [search, setSearch] = useState('');
  const q = useDebounce(search.trim());
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Agent | 'new' | null>(null);

  useEffect(() => setPage(1), [q]);

  const agents = useAgents(page, q);
  const toggle = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) => setAgentActive(id, active),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['agents'] }),
  });

  const columns: Column<Agent>[] = [
    { header: ar.agents.name, cell: (a) => <Link to={`/agents/${a.id}`}>{a.name}</Link> },
    { header: ar.agents.country, cell: (a) => (a.country ? ar.countries[a.country] : '—') },
    { header: ar.agents.phone, cell: (a) => <span className="ltr">{a.phone ?? '—'}</span> },
    { header: ar.common.status, cell: (a) => <StatusBadge active={a.active} /> },
  ];

  if (canManage) {
    columns.push({
      header: ar.common.actions,
      cell: (a) => (
        <div className="row-actions">
          <Button variant="ghost" onClick={() => setEditing(a)}>
            {ar.common.edit}
          </Button>
          <Button variant="ghost" disabled={toggle.isPending} onClick={() => toggle.mutate({ id: a.id, active: !a.active })}>
            {a.active ? ar.common.deactivate : ar.common.activate}
          </Button>
        </div>
      ),
    });
  }

  return (
    <>
      <PageHeader title={ar.agents.title}>{canManage && <Button onClick={() => setEditing('new')}>{ar.agents.add}</Button>}</PageHeader>

      <div className="toolbar">
        <input className="input" type="search" aria-label={ar.common.search} placeholder={ar.agents.searchPlaceholder} value={search} onChange={(event) => setSearch(event.target.value)} />
      </div>

      {agents.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      {toggle.isError && <Notice tone="error">{toggle.error.message}</Notice>}

      <DataTable columns={columns} rows={agents.data?.data ?? []} rowKey={(a) => a.id} loading={agents.isPending} />
      <Pagination meta={agents.data?.meta} onPage={setPage} />

      {editing !== null && <AgentForm agent={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  );
}
