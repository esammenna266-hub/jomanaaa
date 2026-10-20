import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { ShipmentStatus, ShipmentSummary } from '../../shared/api/types';
import { formatDate, formatMoney } from '../../shared/format';
import { useDebounce } from '../../shared/hooks/useDebounce';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { currencyName, useCurrencies } from '../master-data/api';
import { useShipments } from './api';
import { ShipmentStatusBadge } from './ShipmentStatusBadge';

const STATUSES = Object.keys(ar.shipments.status) as ShipmentStatus[];

export function ShipmentsPage() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const currencies = useCurrencies();

  const [search, setSearch] = useState('');
  const q = useDebounce(search.trim());
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  useEffect(() => setPage(1), [q, status]);

  const shipments = useShipments(page, q, status);

  const columns: Column<ShipmentSummary>[] = [
    {
      header: ar.shipments.columns.serial,
      cell: (s) => (
        <Link to={`/shipments/${s.id}`} className="ltr">
          {s.serial_no}
        </Link>
      ),
    },
    { header: ar.shipments.columns.customer, cell: (s) => <strong>{s.customer?.name ?? '—'}</strong> },
    { header: ar.shipments.columns.consignee, cell: (s) => s.consignee_name },
    { header: ar.shipments.columns.status, cell: (s) => <ShipmentStatusBadge status={s.status} /> },
    {
      header: ar.shipments.columns.net,
      cell: (s) => (
        <>
          <span className="ltr">{formatMoney(Number(s.price) - Number(s.discount))}</span> {currencyName(currencies.data, s.currency_id)}
        </>
      ),
    },
    { header: ar.shipments.columns.expected, cell: (s) => formatDate(s.expected_date) },
    {
      header: ar.common.actions,
      cell: (s) => (
        <Button variant="ghost" onClick={() => navigate(`/shipments/${s.id}`)}>
          {ar.shipments.open}
        </Button>
      ),
    },
  ];

  return (
    <>
      <PageHeader title={ar.shipments.title}>
        {can('shipment.create') && <Button onClick={() => navigate('/shipments/new')}>{ar.shipments.new}</Button>}
      </PageHeader>

      <div className="toolbar">
        <input
          className="input"
          type="search"
          aria-label={ar.common.search}
          placeholder={ar.shipments.searchPlaceholder}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          autoFocus
        />
        <select className="input" aria-label={ar.common.status} value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">{ar.shipments.allStatuses}</option>
          {STATUSES.map((value) => (
            <option key={value} value={value}>
              {ar.shipments.status[value]}
            </option>
          ))}
        </select>
      </div>

      {shipments.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}

      <DataTable columns={columns} rows={shipments.data?.data ?? []} rowKey={(s) => s.id} loading={shipments.isPending} />
      <Pagination meta={shipments.data?.meta} onPage={setPage} />
    </>
  );
}
