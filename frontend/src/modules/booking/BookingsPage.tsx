import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { Booking, BookingStatus } from '../../shared/api/types';
import { formatDateTime, formatMoney } from '../../shared/format';
import { useDebounce } from '../../shared/hooks/useDebounce';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { ReasonDialog } from '../../shared/ui/ReasonDialog';
import { printDocument } from '../documents/api';
import { TextField } from '../../shared/ui/Fields';
import { cancelBooking, useBookings } from './api';

const tone = (status: BookingStatus) => (status === 'cancelled' ? 'danger' : status === 'paid' ? 'ok' : status === 'reserved' ? 'warn' : 'muted');

export function BookingsPage() {
  const { can } = useAuth();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const q = useDebounce(search.trim());
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [cancelling, setCancelling] = useState<Booking | null>(null);

  useEffect(() => setPage(1), [q, status]);

  const bookings = useBookings(page, q, status);
  const print = useMutation({ mutationFn: (id: number) => printDocument('bus_ticket', id) });

  const canCancel = (booking: Booking) =>
    can('booking.cancel_refund') &&
    (booking.status === 'paid' || booking.status === 'reserved') &&
    (booking.trip?.status === 'open' || booking.trip?.status === 'express_locked');

  const columns: Column<Booking>[] = [
    { header: ar.bookings.columns.serial, cell: (b) => <span className="ltr">{b.serial_no}</span> },
    { header: ar.bookings.columns.passenger, cell: (b) => b.passenger_name },
    {
      header: ar.bookings.columns.trip,
      cell: (b) =>
        b.trip ? (
          <Link to={`/passenger-trips/${b.trip.id}`}>
            {b.trip.route} — {formatDateTime(b.trip.depart_at)}
          </Link>
        ) : (
          '—'
        ),
    },
    { header: ar.bookings.columns.seat, cell: (b) => <span className="ltr">{b.seat_no}</span> },
    { header: ar.bookings.columns.price, cell: (b) => <span className="ltr">{formatMoney(b.sale_price)}</span> },
    { header: ar.bookings.columns.paid, cell: (b) => <span className="ltr">{formatMoney(b.paid)}</span> },
    { header: ar.bookings.columns.status, cell: (b) => <span className={`badge badge--${tone(b.status)}`}>{ar.bookings.status[b.status]}</span> },
    {
      header: ar.common.actions,
      cell: (b) => (
        <div className="row-actions">
          {b.status !== 'cancelled' && (
            <Button variant="ghost" disabled={print.isPending} aria-label={`${ar.booking.printTicket} ${b.serial_no}`} onClick={() => print.mutate(b.id)}>
              {ar.booking.printTicket}
            </Button>
          )}
          {canCancel(b) && (
            <Button variant="ghost" aria-label={`${ar.booking.cancelBooking} ${b.serial_no}`} onClick={() => setCancelling(b)}>
              {ar.booking.cancelBooking}
            </Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader title={ar.bookings.title} />

      <div className="toolbar">
        <TextField label={ar.common.search} value={search} onChange={(event) => setSearch(event.target.value)} placeholder={ar.bookings.searchPlaceholder} />
        <select className="input" aria-label={ar.common.status} value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">{ar.booking.allStatuses}</option>
          {(Object.keys(ar.bookings.status) as BookingStatus[]).map((value) => (
            <option key={value} value={value}>
              {ar.bookings.status[value]}
            </option>
          ))}
        </select>
      </div>

      {bookings.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      {print.isError && <Notice tone="error">{print.error.message}</Notice>}

      <DataTable columns={columns} rows={bookings.data?.data ?? []} rowKey={(b) => b.id} loading={bookings.isPending} />
      <Pagination meta={bookings.data?.meta} onPage={setPage} />

      {cancelling && (
        <ReasonDialog
          title={ar.booking.cancelBooking}
          label={ar.booking.cancelReason}
          confirmLabel={ar.booking.cancelBooking}
          intro={ar.booking.cancelBookingHint}
          danger
          onConfirm={async (reason) => {
            await cancelBooking(cancelling.id, reason);
            await Promise.all([
              queryClient.invalidateQueries({ queryKey: ['bookings'] }),
              queryClient.invalidateQueries({ queryKey: ['passenger-trip'] }),
              queryClient.invalidateQueries({ queryKey: ['passenger-trips'] }),
              queryClient.invalidateQueries({ queryKey: ['treasuries'] }),
            ]);
          }}
          onClose={() => setCancelling(null)}
        />
      )}
    </>
  );
}
