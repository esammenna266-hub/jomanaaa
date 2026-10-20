import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { ExpressShipmentRow, PassengerTrip, Seat } from '../../shared/api/types';
import { formatDateTime, formatMoney } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Spinner } from '../../shared/ui/Spinner';
import { printDocument } from '../documents/api';
import { currencyName, useCurrencies } from '../master-data/api';
import { ShipmentStatusBadge } from '../shipping/ShipmentStatusBadge';
import { isSellable, tripAction, useSeatMap, useTripExpressShipments, type SeatMap } from './api';
import { BookDialog, SeatDialog } from './BookingDialogs';
import { PassengerTripStatusBadge } from './PassengerTripsPage';

type TripAction = 'depart' | 'complete' | 'cancel';

export function PassengerTripFilePage() {
  const id = Number(useParams().id);
  const query = useSeatMap(id);

  if (query.isPending) {
    return <Spinner />;
  }

  if (query.isError) {
    return <Notice tone="error">{query.error.message}</Notice>;
  }

  return <PassengerTripFile map={query.data} />;
}

function PassengerTripFile({ map }: { map: SeatMap }) {
  const { trip, seats } = map;
  const { can } = useAuth();
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const [freeSeat, setFreeSeat] = useState<number | null>(null);
  const [bookedSeat, setBookedSeat] = useState<Seat | null>(null);
  const [confirming, setConfirming] = useState<TripAction | null>(null);

  const canManage = can('trip.manage');
  const sellable = isSellable(trip.status);
  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['passenger-trip', trip.id] }),
      queryClient.invalidateQueries({ queryKey: ['passenger-trips'] }),
      queryClient.invalidateQueries({ queryKey: ['bookings'] }),
    ]);

  const print = useMutation({ mutationFn: () => printDocument('passenger_manifest', trip.id) });
  const act = useMutation({
    mutationFn: (action: TripAction) => tripAction(trip.id, action),
    onSuccess: async () => {
      await refresh();
      setConfirming(null);
    },
    onError: () => setConfirming(null),
  });

  const confirmText = { depart: ar.booking.departConfirm, complete: ar.booking.completeConfirm, cancel: ar.booking.cancelTripConfirm } as const;
  const departed = trip.status === 'departed' || trip.status === 'completed';

  return (
    <>
      <PageHeader title={ar.booking.file(trip.route)}>
        <div className="actions-bar">
          {canManage && departed && (
            <Button variant="secondary" loading={print.isPending} onClick={() => print.mutate()}>
              {ar.booking.printManifest}
            </Button>
          )}
          {canManage && sellable && <Button onClick={() => setConfirming('depart')}>{ar.booking.depart}</Button>}
          {canManage && trip.status === 'departed' && <Button onClick={() => setConfirming('complete')}>{ar.booking.complete}</Button>}
          {canManage && sellable && (
            <Button variant="danger" onClick={() => setConfirming('cancel')}>
              {ar.booking.cancelTrip}
            </Button>
          )}
        </div>
      </PageHeader>

      {print.isError && <Notice tone="error">{print.error.message}</Notice>}
      {act.isError && <Notice tone="error">{act.error.message}</Notice>}

      <div className="file-head">
        <PassengerTripStatusBadge status={trip.status} />
        <span className="muted">{ar.booking.seatsOf(trip.booked_seats, trip.sellable_seats)}</span>
        {departed && <span className="muted">{ar.booking.departedHint}</span>}
      </div>

      <section className="card">
        <dl className="dl">
          <dt>{ar.booking.columns.kind}</dt>
          <dd>{ar.booking.kind[trip.kind]}</dd>
          <dt>{ar.booking.columns.departAt}</dt>
          <dd>{formatDateTime(trip.depart_at)}</dd>
          <dt>{ar.booking.checkinAt}</dt>
          <dd>{formatDateTime(trip.checkin_at)}</dd>
          <dt>{ar.booking.vehicle}</dt>
          <dd className="ltr">{trip.vehicle?.plate_no ?? '—'}</dd>
          <dt>{ar.booking.driver}</dt>
          <dd>{trip.driver?.name ?? '—'}</dd>
          <dt>{ar.booking.price}</dt>
          <dd>
            <span className="ltr">{formatMoney(trip.price)}</span> {currencyName(currencies.data, trip.currency_id)}
          </dd>
          {trip.express_seats > 0 && (
            <>
              <dt>{ar.booking.expressSeats}</dt>
              <dd className="ltr">{trip.express_seats}</dd>
              <dt>{ar.booking.expressCutoff}</dt>
              <dd>{formatDateTime(trip.express_cutoff_at)}</dd>
            </>
          )}
        </dl>
      </section>

      {(trip.express_seats > 0 || trip.express_used > 0) && <ExpressSection trip={trip} />}

      <h2 className="section-title">{ar.booking.seatMap}</h2>
      {sellable && <p className="muted">{ar.booking.legend}</p>}
      <ul className="seats">
        {seats.map((seat) => {
          const booked = seat.status === 'booked';
          const label = booked ? ar.booking.bookedSeatLabel(seat.no, seat.booking?.passenger_name) : ar.booking.freeSeatLabel(seat.no);
          // Free seats can be sold while the trip is open; booked ones open their details when the user may see them.
          const clickable = booked ? seat.booking !== undefined : sellable && can('booking.create');

          return (
            <li key={seat.no}>
              <button
                type="button"
                className={`seat seat--${booked ? 'booked' : 'free'}`}
                aria-label={label}
                disabled={!clickable}
                onClick={() => (booked ? setBookedSeat(seat) : setFreeSeat(seat.no))}
              >
                <strong className="ltr">{seat.no}</strong>
                {seat.booking && <span className="seat__name">{seat.booking.passenger_name}</span>}
              </button>
            </li>
          );
        })}
      </ul>

      <p>
        <Link to="/passenger-trips">← {ar.booking.title}</Link>
      </p>

      {freeSeat !== null && <BookDialog trip={trip} seatNo={freeSeat} onDone={refresh} onClose={() => setFreeSeat(null)} />}
      {bookedSeat !== null && (
        <SeatDialog
          trip={trip}
          seat={bookedSeat}
          canCancel={can('booking.cancel_refund') && sellable}
          canPrint={can('booking.view')}
          onDone={refresh}
          onClose={() => setBookedSeat(null)}
        />
      )}
      {confirming && (
        <Modal title={ar.booking[confirming === 'cancel' ? 'cancelTrip' : confirming]} onClose={() => setConfirming(null)}>
          <p>{confirmText[confirming]}</p>
          <div className="form-actions">
            <Button variant={confirming === 'cancel' ? 'danger' : 'primary'} loading={act.isPending} onClick={() => act.mutate(confirming)}>
              {ar.booking[confirming === 'cancel' ? 'cancelTrip' : confirming]}
            </Button>
            <Button variant="secondary" onClick={() => setConfirming(null)}>
              {ar.common.cancel}
            </Button>
          </div>
        </Modal>
      )}
    </>
  );
}

/** Express seats of the trip: how many are used, what was released to passengers, and the shipments riding it. */
function ExpressSection({ trip }: { trip: PassengerTrip }) {
  const { can } = useAuth();
  const canSeeShipments = can('shipment.view');
  const shipments = useTripExpressShipments(trip.id, canSeeShipments);

  const columns: Column<ExpressShipmentRow>[] = [
    { header: ar.booking.expressColumns.serial, cell: (s) => <Link to={`/shipments/${s.id}`}>{s.serial_no}</Link> },
    { header: ar.booking.expressColumns.customer, cell: (s) => s.customer?.name ?? '—' },
    { header: ar.booking.expressColumns.consignee, cell: (s) => s.consignee_name },
    { header: ar.booking.expressColumns.destination, cell: (s) => s.destination?.name ?? '—' },
    { header: ar.booking.expressColumns.status, cell: (s) => <ShipmentStatusBadge status={s.status} /> },
  ];

  return (
    <section className="card">
      <h2>{ar.booking.expressTitle}</h2>
      <p>
        {ar.booking.expressUsage(trip.express_used, trip.express_seats)}
        {!trip.express_locked && trip.express_free > 0 && <span className="muted"> — {ar.booking.expressFree}: <span className="ltr">{trip.express_free}</span></span>}
      </p>
      {trip.express_locked && <p className="muted">{ar.booking.expressLockedHint}</p>}
      {trip.express_released > 0 && <p className="muted">{ar.booking.expressReleased(trip.express_released)}</p>}

      {canSeeShipments &&
        (shipments.isError ? (
          <Notice tone="error">{shipments.error.message}</Notice>
        ) : shipments.data && shipments.data.length === 0 ? (
          <p className="muted">{ar.booking.expressEmpty}</p>
        ) : (
          <DataTable columns={columns} rows={shipments.data ?? []} rowKey={(s) => s.id} loading={shipments.isPending} />
        ))}
    </section>
  );
}
