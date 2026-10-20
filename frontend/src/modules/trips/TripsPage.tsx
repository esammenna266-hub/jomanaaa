import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { CargoTrip, TripStage } from '../../shared/api/types';
import { formatDateTime } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { TRIP_STAGES, useTrips } from './api';

const tone = (stage: TripStage) => (stage === 'cancelled' ? 'danger' : stage === 'arrived' || stage === 'completed' ? 'ok' : 'info');

export function TripStageBadge({ stage }: { stage: TripStage }) {
  return <span className={`badge badge--${tone(stage)}`}>{ar.trips.stage[stage]}</span>;
}

export function TripsPage() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const [stage, setStage] = useState('');
  const [page, setPage] = useState(1);

  useEffect(() => setPage(1), [stage]);

  const trips = useTrips(page, stage);

  const columns: Column<CargoTrip>[] = [
    {
      header: ar.trips.columns.serial,
      cell: (t) => (
        <Link to={`/trips/${t.id}`} className="ltr">
          {t.serial_no}
        </Link>
      ),
    },
    { header: ar.trips.columns.vehicle, cell: (t) => <span className="ltr">{t.vehicle?.plate_no ?? '—'}</span> },
    { header: ar.trips.columns.driver, cell: (t) => t.driver?.name ?? '—' },
    { header: ar.trips.columns.stage, cell: (t) => <TripStageBadge stage={t.stage} /> },
    { header: ar.trips.columns.shipments, cell: (t) => <span className="ltr">{t.shipments_count ?? '—'}</span> },
    { header: ar.trips.columns.departed, cell: (t) => formatDateTime(t.departed_at) },
  ];

  return (
    <>
      <PageHeader title={ar.trips.title}>
        {can('cargo_trip.manage') && <Button onClick={() => navigate('/trips/new')}>{ar.trips.new}</Button>}
      </PageHeader>

      <div className="toolbar">
        <select className="input" aria-label={ar.common.status} value={stage} onChange={(event) => setStage(event.target.value)}>
          <option value="">{ar.trips.allStages}</option>
          {[...TRIP_STAGES, 'cancelled' as const].map((value) => (
            <option key={value} value={value}>
              {ar.trips.stage[value]}
            </option>
          ))}
        </select>
      </div>

      {trips.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}

      <DataTable columns={columns} rows={trips.data?.data ?? []} rowKey={(t) => t.id} loading={trips.isPending} />
      <Pagination meta={trips.data?.meta} onPage={setPage} />
    </>
  );
}
