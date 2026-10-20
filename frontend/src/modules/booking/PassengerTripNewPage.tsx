import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { ar } from '../../i18n/ar';
import type { PassengerTripKind } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { useActiveDrivers, useBuses, useCurrencies } from '../master-data/api';
import { createPassengerTrip, type TripPayload } from './api';

const SHOWN = [
  'kind', 'route', 'depart_at', 'checkin_at', 'vehicle_id', 'driver_id',
  'passenger_seats', 'express_seats', 'express_cutoff_at', 'price', 'currency_id',
];

/** datetime-local gives a wall-clock string; send it as an exact instant. */
const toInstant = (value: string) => new Date(value).toISOString();

export function PassengerTripNewPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const buses = useBuses();
  const drivers = useActiveDrivers();
  const currencies = useCurrencies();

  const [kind, setKind] = useState<PassengerTripKind>('bus');
  const [route, setRoute] = useState('');
  const [departAt, setDepartAt] = useState('');
  const [checkinAt, setCheckinAt] = useState('');
  const [vehicleId, setVehicleId] = useState('');
  const [driverId, setDriverId] = useState('');
  const [seats, setSeats] = useState('');
  const [expressSeats, setExpressSeats] = useState('0');
  const [cutoffAt, setCutoffAt] = useState('');
  const [price, setPrice] = useState('');
  const [currencyId, setCurrencyId] = useState('');

  const mutation = useMutation({
    mutationFn: () => {
      const payload: TripPayload = {
        kind,
        route: route.trim(),
        depart_at: toInstant(departAt),
        passenger_seats: Number(seats),
        express_seats: Number(expressSeats || 0),
        price: price.trim(),
        currency_id: Number(currencyId || currencies.data?.[0]?.id),
      };
      if (checkinAt) payload.checkin_at = toInstant(checkinAt);
      if (cutoffAt) payload.express_cutoff_at = toInstant(cutoffAt);
      if (kind === 'bus' && vehicleId) payload.vehicle_id = Number(vehicleId);
      if (kind === 'bus' && driverId) payload.driver_id = Number(driverId);

      return createPassengerTrip(payload);
    },
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['passenger-trips'] });
      navigate(`/passenger-trips/${result.data.id}`);
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  // Picking a bus fills in its seat count; the number can still be lowered by hand.
  function onVehicle(id: string) {
    setVehicleId(id);
    const seatCount = buses.data?.find((bus) => String(bus.id) === id)?.seats;
    if (seatCount) setSeats(String(seatCount));
  }

  const ready = route.trim() !== '' && departAt !== '' && Number(seats) > 0 && price.trim() !== '';
  const err = (field: string) => fieldError(mutation.error, field);

  return (
    <>
      <PageHeader title={ar.booking.newTitle} />

      <form onSubmit={onSubmit} noValidate className="shipment-form">
        {formError(mutation.error, SHOWN) && <Notice tone="error">{formError(mutation.error, SHOWN)}</Notice>}

        <div className="form-grid">
          <SelectField label={ar.booking.columns.kind} value={kind} onChange={(event) => setKind(event.target.value as PassengerTripKind)} error={err('kind')} required>
            {(['bus', 'flight', 'ship'] as const).map((value) => (
              <option key={value} value={value}>
                {ar.booking.kind[value]}
              </option>
            ))}
          </SelectField>
          <TextField label={ar.booking.route} value={route} onChange={(event) => setRoute(event.target.value)} error={err('route')} required />
          <TextField label={ar.booking.departAt} type="datetime-local" value={departAt} onChange={(event) => setDepartAt(event.target.value)} error={err('depart_at')} ltr required />
          <TextField label={ar.booking.checkinAt} type="datetime-local" value={checkinAt} onChange={(event) => setCheckinAt(event.target.value)} error={err('checkin_at')} ltr optional />
          {kind === 'bus' && (
            <>
              <SelectField label={ar.booking.vehicle} value={vehicleId} onChange={(event) => onVehicle(event.target.value)} error={err('vehicle_id')} optional>
                <option value="">{ar.common.choose}</option>
                {buses.data?.map((bus) => (
                  <option key={bus.id} value={bus.id}>
                    {bus.plate_no}
                    {bus.seats ? ` (${bus.seats})` : ''}
                  </option>
                ))}
              </SelectField>
              <SelectField label={ar.booking.driver} value={driverId} onChange={(event) => setDriverId(event.target.value)} error={err('driver_id')} optional>
                <option value="">{ar.common.choose}</option>
                {drivers.data?.map((driver) => (
                  <option key={driver.id} value={driver.id}>
                    {driver.name}
                  </option>
                ))}
              </SelectField>
            </>
          )}
          <TextField label={ar.booking.passengerSeats} value={seats} onChange={(event) => setSeats(event.target.value)} error={err('passenger_seats')} inputMode="numeric" ltr required />
          <TextField label={ar.booking.expressSeats} value={expressSeats} onChange={(event) => setExpressSeats(event.target.value)} error={err('express_seats')} inputMode="numeric" ltr optional />
          {Number(expressSeats) > 0 && (
            <TextField
              label={ar.booking.expressCutoff}
              type="datetime-local"
              value={cutoffAt}
              onChange={(event) => setCutoffAt(event.target.value)}
              hint={ar.booking.expressCutoffHint}
              error={err('express_cutoff_at')}
              ltr
              optional
            />
          )}
          <TextField label={ar.booking.price} value={price} onChange={(event) => setPrice(event.target.value)} error={err('price')} inputMode="decimal" ltr required />
          <SelectField label={ar.booking.currency} value={currencyId || String(currencies.data?.[0]?.id ?? '')} onChange={(event) => setCurrencyId(event.target.value)} error={err('currency_id')} required>
            {currencies.data?.map((currency) => (
              <option key={currency.id} value={currency.id}>
                {currency.name_ar}
              </option>
            ))}
          </SelectField>
        </div>

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={!ready}>
            {ar.booking.create}
          </Button>
          <Button variant="ghost" onClick={() => navigate('/passenger-trips')}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </>
  );
}
