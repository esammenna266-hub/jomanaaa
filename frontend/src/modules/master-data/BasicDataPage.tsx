import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import { useDebounce } from '../../shared/hooks/useDebounce';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { StatusBadge } from '../../shared/ui/StatusBadge';
import { BasicDataForm } from './BasicDataForm';
import { setBasicRecordActive, useBasicData, type BasicDataKey, type BasicRecord } from './basicData';

const TABS: BasicDataKey[] = ['destinations', 'border-points', 'clearing-agents', 'vehicles', 'drivers'];

const text = (value: unknown) => (value === null || value === undefined || value === '' ? '—' : String(value));
const ltr = (value: unknown) => <span className="ltr">{text(value)}</span>;

function columnsFor(resource: BasicDataKey): Column<BasicRecord>[] {
  const f = ar.basicData.fields;
  const name: Column<BasicRecord> = { header: f.name, cell: (r) => <strong>{text(r.name)}</strong> };

  switch (resource) {
    case 'destinations':
      return [name, { header: f.country, cell: (r) => (r.country === 'SD' || r.country === 'EG' ? ar.countries[r.country] : '—') }];
    case 'border-points':
      return [name];
    case 'clearing-agents':
      return [name, { header: f.phone, cell: (r) => ltr(r.phone) }];
    case 'vehicles':
      return [
        { header: f.plate_no, cell: (r) => <strong className="ltr">{text(r.plate_no)}</strong> },
        { header: f.category, cell: (r) => ar.shipments.vehicleCategory[r.category as keyof typeof ar.shipments.vehicleCategory] ?? text(r.category) },
        { header: ar.basicData.capacity, cell: (r) => (r.category === 'bus' ? ar.basicData.seatsCount(text(r.seats)) : r.max_ton ? ar.basicData.tons(text(r.max_ton)) : '—') },
        { header: f.ownership, cell: (r) => (r.ownership === 'rented' ? 'إيجار' : 'ملك الشركة') },
      ];
    case 'drivers':
      return [name, { header: f.phone, cell: (r) => ltr(r.phone) }, { header: f.license_no, cell: (r) => ltr(r.license_no) }, { header: f.is_international, cell: (r) => (r.is_international ? ar.common.yes : ar.common.no) }];
  }
}

/** Where shipments go, border points, clearing agents, vehicles and drivers: the lists the company keeps up to date itself. */
export function BasicDataPage() {
  const { can } = useAuth();
  const queryClient = useQueryClient();
  const canManage = can('masterdata.manage');

  const [resource, setResource] = useState<BasicDataKey>('destinations');
  const [search, setSearch] = useState('');
  const q = useDebounce(search.trim());
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<BasicRecord | 'new' | null>(null);

  useEffect(() => setPage(1), [q, resource]);

  const records = useBasicData(resource, page, q);
  const toggle = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) => setBasicRecordActive(resource, id, active),
    onSuccess: () => Promise.all([queryClient.invalidateQueries({ queryKey: ['basic-data', resource] }), queryClient.invalidateQueries({ queryKey: ['lookup'] })]),
  });

  const columns = columnsFor(resource);
  columns.push({ header: ar.common.status, cell: (r) => <StatusBadge active={r.active} /> });
  if (canManage) {
    columns.push({
      header: ar.common.actions,
      cell: (r) => (
        <div className="row-actions">
          <Button variant="ghost" onClick={() => setEditing(r)}>
            {ar.common.edit}
          </Button>
          <Button variant="ghost" disabled={toggle.isPending} onClick={() => toggle.mutate({ id: r.id, active: !r.active })}>
            {r.active ? ar.common.deactivate : ar.common.activate}
          </Button>
        </div>
      ),
    });
  }

  return (
    <>
      <PageHeader title={ar.basicData.title}>{canManage && <Button onClick={() => setEditing('new')}>{ar.basicData.addTitle(ar.basicData.single[resource])}</Button>}</PageHeader>

      <div className="tabs" role="tablist" aria-label={ar.basicData.title}>
        {TABS.map((tab) => (
          <button key={tab} type="button" role="tab" aria-selected={tab === resource} className={`tabs__tab${tab === resource ? ' tabs__tab--active' : ''}`} onClick={() => setResource(tab)}>
            {ar.basicData.tabs[tab]}
          </button>
        ))}
      </div>

      <div className="toolbar">
        <input className="input" type="search" aria-label={ar.common.search} placeholder={ar.common.search} value={search} onChange={(event) => setSearch(event.target.value)} />
      </div>

      {records.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      {toggle.isError && <Notice tone="error">{toggle.error.message}</Notice>}

      <DataTable columns={columns} rows={records.data?.data ?? []} rowKey={(r) => r.id} loading={records.isPending} />
      <Pagination meta={records.data?.meta} onPage={setPage} />

      {editing !== null && <BasicDataForm key={`${resource}-${editing === 'new' ? 'new' : editing.id}`} resource={resource} record={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  );
}
