import { Link } from 'react-router-dom';
import { ar } from '../../i18n/ar';
import type { ShipmentStatus } from '../../shared/api/types';
import { formatMoney } from '../../shared/format';
import { Notice } from '../../shared/ui/Notice';
import { Spinner } from '../../shared/ui/Spinner';
import { useDashboard } from './api';

/** The owner's landing numbers: money in the treasuries, today's collections and the work still open. */
export function DashboardCards() {
  const dashboard = useDashboard(true);

  if (dashboard.isPending) {
    return <Spinner />;
  }

  if (dashboard.isError) {
    return <Notice tone="error">{ar.common.loadFailed}</Notice>;
  }

  const data = dashboard.data;
  const openShipments = Object.entries(data.open_shipments);
  const totalOpen = openShipments.reduce((sum, [, count]) => sum + count, 0);

  return (
    <>
      <h2 className="section-title">{ar.dashboard.title}</h2>
      <div className="cards">
        <div className="card">
          <div className="card__label">{ar.dashboard.treasuries}</div>
          {data.treasury_totals.length === 0 && <div className="card__value">—</div>}
          {data.treasury_totals.map((item) => (
            <div key={item.currency} className="card__value">
              <span className="ltr">{formatMoney(item.amount)}</span> {item.currency}
            </div>
          ))}
        </div>
        <div className="card">
          <div className="card__label">{ar.dashboard.collectedToday}</div>
          {data.collected_today.length === 0 && <div className="card__value">—</div>}
          {data.collected_today.map((item) => (
            <div key={item.currency} className="card__value">
              <span className="ltr">{formatMoney(item.amount)}</span> {item.currency}
            </div>
          ))}
        </div>
        <div className="card">
          <div className="card__label">{ar.dashboard.openShipments}</div>
          <div className="card__value ltr">{totalOpen}</div>
          <ul className="card__list">
            {openShipments.map(([status, count]) => (
              <li key={status}>
                {ar.shipments.status[status as ShipmentStatus] ?? status}: <span className="ltr">{count}</span>
              </li>
            ))}
          </ul>
        </div>
        <Stat label={ar.dashboard.overdueInstallments} value={data.overdue_installments} warn />
        <Stat label={ar.dashboard.pendingRemittances} value={data.pending_remittances} to="/remittances" />
        <Stat label={ar.dashboard.openPassengerTrips} value={data.passenger_trips_open} to="/passenger-trips" />
        {data.pending_approvals !== null && <Stat label={ar.dashboard.pendingApprovals} value={data.pending_approvals} warn />}
        {data.cargo_trips_on_the_road !== null && <Stat label={ar.dashboard.cargoOnTheRoad} value={data.cargo_trips_on_the_road} to="/trips" />}
        {data.agents_owing !== null && <Stat label={ar.dashboard.agentsOwing} value={data.agents_owing} to="/agents" />}
      </div>
    </>
  );
}

function Stat({ label, value, to, warn = false }: { label: string; value: number; to?: string; warn?: boolean }) {
  const body = (
    <>
      <div className="card__label">{label}</div>
      <div className={`card__value ltr${warn && value > 0 ? ' card__value--warn' : ''}`}>{value}</div>
    </>
  );

  return to ? (
    <Link to={to} className="card card--link">
      {body}
    </Link>
  ) : (
    <div className="card">{body}</div>
  );
}
