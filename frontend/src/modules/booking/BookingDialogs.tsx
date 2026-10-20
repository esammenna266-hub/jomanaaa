import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { Customer, PassengerTrip, Seat } from '../../shared/api/types';
import { formatMoney } from '../../shared/format';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { ReasonDialog } from '../../shared/ui/ReasonDialog';
import { printDocument } from '../documents/api';
import { currencyName, useCurrencies } from '../master-data/api';
import { CustomerPicker } from '../shipping/CustomerPicker';
import { useTreasuries } from '../shipping/api';
import { bookSeat, cancelBooking } from './api';

const SHOWN = ['seat_no', 'passenger_name', 'passport_no', 'destination', 'customer_id', 'sale_price', 'treasury_id', 'trip_id'];

interface DialogProps {
  trip: PassengerTrip;
  onDone: () => Promise<unknown>;
  onClose: () => void;
}

/** Sells one seat. Counter staff collect the full price into a treasury; external offices book on account. */
export function BookDialog({ trip, seatNo, onDone, onClose }: DialogProps & { seatNo: number }) {
  const { user } = useAuth();
  const currencies = useCurrencies();
  const isOffice = user?.agent != null;
  const treasuries = useTreasuries(!isOffice);

  const [name, setName] = useState('');
  const [passport, setPassport] = useState('');
  const [destination, setDestination] = useState('');
  const [customer, setCustomer] = useState<Pick<Customer, 'id' | 'name' | 'phone'> | null>(null);
  const [salePrice, setSalePrice] = useState(trip.price);
  const [treasuryId, setTreasuryId] = useState('');

  const sameCurrency = treasuries.data?.filter((treasury) => treasury.currency_id === trip.currency_id) ?? [];
  // A lone treasury in the trip's currency is the obvious choice.
  const effectiveTreasury = treasuryId || (sameCurrency.length === 1 ? String(sameCurrency[0]?.id) : '');

  const mutation = useMutation({
    mutationFn: () =>
      bookSeat({
        trip_id: trip.id,
        seat_no: seatNo,
        passenger_name: name.trim(),
        ...(passport.trim() ? { passport_no: passport.trim() } : {}),
        ...(destination.trim() ? { destination: destination.trim() } : {}),
        ...(customer ? { customer_id: customer.id } : {}),
        ...(isOffice ? { sale_price: salePrice.trim() } : { treasury_id: Number(effectiveTreasury) }),
      }),
    onSuccess: async () => {
      await onDone();
      onClose();
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  const ready = name.trim() !== '' && (isOffice ? salePrice.trim() !== '' : effectiveTreasury !== '');
  const err = (field: string) => fieldError(mutation.error, field);

  return (
    <Modal title={ar.booking.bookTitle(seatNo)} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {formError(mutation.error, SHOWN) && <Notice tone="error">{formError(mutation.error, SHOWN)}</Notice>}
        {/* A seat taken a moment ago is reported on the seat field: show it at the top, the form has no such input. */}
        {err('seat_no') && <Notice tone="error">{err('seat_no')}</Notice>}
        {err('trip_id') && <Notice tone="error">{err('trip_id')}</Notice>}

        <TextField label={ar.booking.passengerName} value={name} onChange={(event) => setName(event.target.value)} error={err('passenger_name')} required />
        <TextField label={ar.booking.passport} value={passport} onChange={(event) => setPassport(event.target.value)} error={err('passport_no')} ltr optional />
        <TextField label={ar.booking.destination} value={destination} onChange={(event) => setDestination(event.target.value)} error={err('destination')} optional />
        <CustomerPicker value={customer} onChange={setCustomer} error={err('customer_id')} />

        {isOffice ? (
          <>
            <Notice>{ar.booking.officeHint}</Notice>
            <TextField label={ar.booking.salePrice} value={salePrice} onChange={(event) => setSalePrice(event.target.value)} hint={ar.booking.salePriceHint} error={err('sale_price')} inputMode="decimal" ltr required />
          </>
        ) : (
          <>
            <p>
              <strong>
                {ar.booking.amountDue(`${formatMoney(trip.price)} ${currencyName(currencies.data, trip.currency_id)}`)}
              </strong>
            </p>
            <SelectField label={ar.booking.treasury} value={effectiveTreasury} onChange={(event) => setTreasuryId(event.target.value)} error={err('treasury_id')} required>
              <option value="">{ar.common.choose}</option>
              {sameCurrency.map((treasury) => (
                <option key={treasury.id} value={treasury.id}>
                  {ar.shipments.treasuryMethod[treasury.method]} — {treasury.currency?.name_ar} ({treasury.branch?.name})
                </option>
              ))}
            </SelectField>
          </>
        )}

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={!ready}>
            {ar.booking.bookNow}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

/** A booked seat: who, how much, the ticket, and cancelling (before the trip leaves). */
export function SeatDialog({ trip, seat, canCancel, canPrint, onDone, onClose }: DialogProps & { seat: Seat; canCancel: boolean; canPrint: boolean }) {
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const [cancelling, setCancelling] = useState(false);
  const booking = seat.booking;

  const print = useMutation({ mutationFn: (id: number) => printDocument('bus_ticket', id) });

  if (cancelling && booking) {
    return (
      <ReasonDialog
        title={ar.booking.cancelBooking}
        label={ar.booking.cancelReason}
        confirmLabel={ar.booking.cancelBooking}
        intro={ar.booking.cancelBookingHint}
        danger
        onConfirm={async (reason) => {
          await cancelBooking(booking.id, reason);
          await Promise.all([onDone(), queryClient.invalidateQueries({ queryKey: ['bookings'] }), queryClient.invalidateQueries({ queryKey: ['treasuries'] })]);
        }}
        onClose={onClose}
      />
    );
  }

  const money = (value: string) => `${formatMoney(value)} ${currencyName(currencies.data, trip.currency_id)}`;

  return (
    <Modal title={ar.booking.bookTitle(seat.no)} onClose={onClose}>
      {print.isError && <Notice tone="error">{print.error.message}</Notice>}
      {booking ? (
        <dl className="dl">
          <dt>{ar.bookings.columns.serial}</dt>
          <dd className="ltr">{booking.serial_no}</dd>
          <dt>{ar.booking.passengerName}</dt>
          <dd>{booking.passenger_name}</dd>
          <dt>{ar.booking.passport}</dt>
          <dd className="ltr">{booking.passport_no ?? '—'}</dd>
          <dt>{ar.booking.destination}</dt>
          <dd>{booking.destination ?? '—'}</dd>
          <dt>{ar.bookings.columns.price}</dt>
          <dd>{money(booking.sale_price)}</dd>
          <dt>{ar.booking.paid}</dt>
          <dd>{money(booking.paid)}</dd>
          <dt>{ar.bookings.columns.status}</dt>
          <dd>{ar.bookings.status[booking.status]}</dd>
          {seat.booked_by && (
            <>
              <dt>{ar.booking.bookedBy}</dt>
              <dd>{seat.booked_by.agent ?? seat.booked_by.branch ?? '—'}</dd>
            </>
          )}
        </dl>
      ) : (
        <Notice>{ar.booking.bookedSeatLabel(seat.no)}</Notice>
      )}

      <div className="form-actions">
        {booking && canPrint && (
          <Button variant="secondary" loading={print.isPending} onClick={() => print.mutate(booking.id)}>
            {ar.booking.printTicket}
          </Button>
        )}
        {booking && canCancel && (
          <Button variant="danger" onClick={() => setCancelling(true)}>
            {ar.booking.cancelBooking}
          </Button>
        )}
        <Button variant="ghost" onClick={onClose}>
          {ar.common.close}
        </Button>
      </div>
    </Modal>
  );
}
