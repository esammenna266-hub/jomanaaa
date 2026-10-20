import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { PassengerTrip, PassengerTripStatus } from '../../shared/api/types';
import { formatDateTime, formatMoney } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { CheckboxField } from '../../shared/ui/Fields';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { currencyName, useCurrencies } from '../master-data/api';
import { usePassengerTrips } from './api';

const tone = (status: PassengerTripStatus) =>
  status === 'cancelled' ? 'danger' : status === 'completed' ? 'ok' : status === 'departed' ? 'muted' : status === 'express_locked' ? 'warn' : 'info';

export function PassengerTripStatusBadge({ status }: { status: PassengerTripStatus }) {
  return <span className={`badge badge--${tone(status)}`}>{ar.booking.status[status]}</span>;
}

export function PassengerTripsPage() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const currencies = useCurrencies();
  const [kind, setKind] = useState('');
  const [status, setStatus] = useState('');
  const [sellable, setSellable] = useState(false);
  const [page, setPage] = useState(1);

  useEffect(() => setPage(1), [kind, status, sellable]);

  const trips = usePassengerTrips(page, { kind, status, sellable });

  const columns: Column<PassengerTrip>[] = [
    {
      header: ar.booking.columns.route,
      cell: (t) => <Link to={`/passenger-trips/${t.id}`}>{t.route}</Link>,
    },
    { header: ar.booking.columns.kind, cell: (t) => ar.booking.kind[t.kind] },
    { header: ar.booking.columns.departAt, cell: (t) => formatDateTime(t.depart_at) },
    {
      header: ar.booking.columns.price,
      cell: (t) => (
        <>
          <span className="ltr">{formatMoney(t.price)}</span> {currencyName(currencies.data, t.currency_id)}
        </>
      ),
    },
    { header: ar.booking.columns.seats, cell: (t) => <span className="ltr">{ar.booking.seatsOf(t.booked_seats, t.sellable_seats)}</span> },
    { header: ar.booking.columns.status, cell: (t) => <PassengerTripStatusBadge status={t.status} /> },
  ];

  return (
    <>
      <PageHeader title={ar.booking.title}>
        {can('trip.manage') && <Button onClick={() => navigate('/passenger-trips/new')}>{ar.booking.new}</Button>}
      </PageHeader>

      <div className="toolbar">
        <select className="input" aria-label={ar.booking.columns.kind} value={kind} onChange={(event) => setKind(event.target.value)}>
          <option value="">{ar.booking.allKinds}</option>
          {(['bus', 'flight', 'ship'] as const).map((value) => (
            <option key={value} value={value}>
              {ar.booking.kind[value]}
            </option>
          ))}
        </select>
        <select className="input" aria-label={ar.common.status} value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">{ar.booking.allStatuses}</option>
          {(Object.keys(ar.booking.status) as PassengerTripStatus[]).map((value) => (
            <option key={value} value={value}>
              {ar.booking.status[value]}
            </option>
          ))}
        </select>
        <CheckboxField label={ar.booking.onlySellable} checked={sellable} onChange={(event) => setSellable(event.target.checked)} />
      </div>

      {trips.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}

      <DataTable columns={columns} rows={trips.data?.data ?? []} rowKey={(t) => t.id} loading={trips.isPending} />
      <Pagination meta={trips.data?.meta} onPage={setPage} />
    </>
  );
}
