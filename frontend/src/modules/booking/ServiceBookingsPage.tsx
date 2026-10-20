import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { ServiceBooking, ServiceBookingStatus } from '../../shared/api/types';
import { formatDate, formatMoney } from '../../shared/format';
import { fieldError, formError } from '../../shared/forms/errors';
import { useDebounce } from '../../shared/hooks/useDebounce';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { SelectField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { ReasonDialog } from '../../shared/ui/ReasonDialog';
import { currencyName, useCurrencies } from '../master-data/api';
import { useTreasuries } from '../shipping/api';
import { cancelServiceBooking, collectServiceBooking, completeServiceBooking, useServiceBookings } from './serviceApi';
import { ServiceBookingForm } from './ServiceBookingForm';

const tone = (status: ServiceBookingStatus) => (status === 'cancelled' ? 'danger' : status === 'reserved' ? 'warn' : status === 'paid' ? 'ok' : 'muted');

export function ServiceBookingsPage() {
  const { can, user } = useAuth();
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const isOffice = user?.agent != null;

  const [search, setSearch] = useState('');
  const q = useDebounce(search.trim());
  const [type, setType] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [collecting, setCollecting] = useState<ServiceBooking | null>(null);
  const [cancelling, setCancelling] = useState<ServiceBooking | null>(null);

  useEffect(() => setPage(1), [q, type, status]);

  const bookings = useServiceBookings(page, q, type, status);
  const refresh = () => Promise.all([queryClient.invalidateQueries({ queryKey: ['service-bookings'] }), queryClient.invalidateQueries({ queryKey: ['treasuries'] })]);
  const complete = useMutation({ mutationFn: (id: number) => completeServiceBooking(id), onSuccess: refresh });

  const columns: Column<ServiceBooking>[] = [
    { header: ar.bookings.columns.serial, cell: (b) => <span className="ltr">{b.serial_no}</span> },
    { header: ar.services.type, cell: (b) => ar.services.types[b.type] },
    { header: ar.services.customer, cell: (b) => b.customer?.name ?? '—' },
    { header: ar.services.details, cell: (b) => <span className="wrap">{b.details}</span> },
    {
      header: ar.services.dates,
      cell: (b) => (b.start_date ? `${formatDate(b.start_date)}${b.end_date ? ` → ${formatDate(b.end_date)}` : ''}` : '—'),
    },
    {
      header: ar.services.price,
      cell: (b) => (
        <>
          <span className="ltr">{formatMoney(b.price)}</span> {currencyName(currencies.data, b.currency_id)}
        </>
      ),
    },
    { header: ar.bookings.columns.paid, cell: (b) => <span className="ltr">{formatMoney(b.paid)}</span> },
    { header: ar.bookings.columns.status, cell: (b) => <span className={`badge badge--${tone(b.status)}`}>{ar.services.status[b.status]}</span> },
    {
      header: ar.common.actions,
      cell: (b) => (
        <div className="row-actions">
          {b.status === 'reserved' && can('booking.create') && !isOffice && (
            <Button variant="ghost" aria-label={`${ar.services.collect} ${b.serial_no}`} onClick={() => setCollecting(b)}>
              {ar.services.collect}
            </Button>
          )}
          {b.status === 'paid' && can('booking.create') && (
            <Button variant="ghost" aria-label={`${ar.services.complete} ${b.serial_no}`} disabled={complete.isPending} onClick={() => complete.mutate(b.id)}>
              {ar.services.complete}
            </Button>
          )}
          {(b.status === 'reserved' || b.status === 'paid') && can('booking.cancel_refund') && (
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
      <PageHeader title={ar.services.title}>{can('booking.create') && <Button onClick={() => setCreating(true)}>{ar.services.new}</Button>}</PageHeader>

      <div className="toolbar">
        <input className="input" type="search" aria-label={ar.common.search} placeholder={ar.services.searchPlaceholder} value={search} onChange={(event) => setSearch(event.target.value)} />
        <select className="input" aria-label={ar.services.type} value={type} onChange={(event) => setType(event.target.value)}>
          <option value="">{ar.services.allTypes}</option>
          <option value="hotel">{ar.services.types.hotel}</option>
          <option value="car">{ar.services.types.car}</option>
        </select>
        <select className="input" aria-label={ar.common.status} value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">{ar.booking.allStatuses}</option>
          {(Object.keys(ar.services.status) as ServiceBookingStatus[]).map((value) => (
            <option key={value} value={value}>
              {ar.services.status[value]}
            </option>
          ))}
        </select>
      </div>

      {bookings.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      {complete.isError && <Notice tone="error">{complete.error.message}</Notice>}

      <DataTable columns={columns} rows={bookings.data?.data ?? []} rowKey={(b) => b.id} loading={bookings.isPending} />
      <Pagination meta={bookings.data?.meta} onPage={setPage} />

      {creating && <ServiceBookingForm onClose={() => setCreating(false)} />}
      {collecting && <CollectDialog booking={collecting} onDone={refresh} onClose={() => setCollecting(null)} />}
      {cancelling && (
        <ReasonDialog
          title={ar.booking.cancelBooking}
          label={ar.booking.cancelReason}
          confirmLabel={ar.booking.cancelBooking}
          intro={ar.services.cancelHint}
          danger
          onConfirm={async (reason) => {
            await cancelServiceBooking(cancelling.id, reason);
            await refresh();
          }}
          onClose={() => setCancelling(null)}
        />
      )}
    </>
  );
}

function CollectDialog({ booking, onDone, onClose }: { booking: ServiceBooking; onDone: () => Promise<unknown>; onClose: () => void }) {
  const currencies = useCurrencies();
  const treasuries = useTreasuries(true);
  const [treasuryId, setTreasuryId] = useState('');

  const sameCurrency = treasuries.data?.filter((treasury) => treasury.currency_id === booking.currency_id) ?? [];
  const effectiveTreasury = treasuryId || (sameCurrency.length === 1 ? String(sameCurrency[0]?.id) : '');

  const mutation = useMutation({
    mutationFn: () => collectServiceBooking(booking.id, Number(effectiveTreasury)),
    onSuccess: async () => {
      await onDone();
      onClose();
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  return (
    <Modal title={ar.services.collect} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {formError(mutation.error, ['treasury_id']) && <Notice tone="error">{formError(mutation.error, ['treasury_id'])}</Notice>}
        <p>
          <strong>{ar.booking.amountDue(`${formatMoney(booking.price)} ${currencyName(currencies.data, booking.currency_id)}`)}</strong>
        </p>
        <SelectField label={ar.services.treasury} value={effectiveTreasury} onChange={(event) => setTreasuryId(event.target.value)} error={fieldError(mutation.error, 'treasury_id')} required>
          <option value="">{ar.common.choose}</option>
          {sameCurrency.map((treasury) => (
            <option key={treasury.id} value={treasury.id}>
              {ar.shipments.treasuryMethod[treasury.method]} — {treasury.currency?.name_ar} ({treasury.branch?.name})
            </option>
          ))}
        </SelectField>
        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={effectiveTreasury === ''}>
            {ar.services.collect}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
