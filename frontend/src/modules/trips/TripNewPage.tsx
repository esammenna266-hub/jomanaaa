import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ar } from '../../i18n/ar';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Spinner } from '../../shared/ui/Spinner';
import { useActiveDrivers, useCurrencies, useLookup, useTrucks } from '../master-data/api';
import { createTrip, useLoadableShipments } from './api';

const SHOWN = [
  'vehicle_id', 'driver_id', 'border_point_id', 'clearing_agent_id', 'shipment_ids', 'fingerprint',
  'pickup_point', 'delivery_point', 'delivery_days', 'daily_penalty', 'currency_id',
];

export function TripNewPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const trucks = useTrucks();
  const drivers = useActiveDrivers();
  const borders = useLookup('border-points');
  const clearing = useLookup('clearing-agents');
  const currencies = useCurrencies();
  const loadable = useLoadableShipments();

  const [vehicleId, setVehicleId] = useState('');
  const [driverId, setDriverId] = useState('');
  const [borderId, setBorderId] = useState('');
  const [clearingId, setClearingId] = useState('');
  const [selected, setSelected] = useState<number[]>([]);
  const [pickup, setPickup] = useState('');
  const [delivery, setDelivery] = useState('');
  const [days, setDays] = useState('');
  const [penalty, setPenalty] = useState('');
  const [penaltyCurrency, setPenaltyCurrency] = useState('');
  const [fingerprint, setFingerprint] = useState<File | null>(null);

  const mutation = useMutation({
    mutationFn: () => {
      const form = new FormData();
      form.append('vehicle_id', vehicleId);
      form.append('driver_id', driverId);
      selected.forEach((id) => form.append('shipment_ids[]', String(id)));
      if (fingerprint) form.append('fingerprint', fingerprint);
      if (borderId) form.append('border_point_id', borderId);
      if (clearingId) form.append('clearing_agent_id', clearingId);
      if (pickup.trim()) form.append('pickup_point', pickup.trim());
      if (delivery.trim()) form.append('delivery_point', delivery.trim());
      if (days.trim()) form.append('delivery_days', days.trim());
      if (penalty.trim()) {
        form.append('daily_penalty', penalty.trim());
        form.append('currency_id', penaltyCurrency || String(currencies.data?.[0]?.id ?? ''));
      }

      return createTrip(form);
    },
    onSuccess: async (result) => {
      await Promise.all([queryClient.invalidateQueries({ queryKey: ['trips'] }), queryClient.invalidateQueries({ queryKey: ['shipments'] })]);
      navigate(`/trips/${result.data.id}`);
    },
  });

  const toggle = (id: number) => setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  const ready = vehicleId !== '' && driverId !== '' && selected.length > 0 && fingerprint !== null;

  return (
    <>
      <PageHeader title={ar.trips.newTitle} />

      <form onSubmit={onSubmit} noValidate className="shipment-form">
        {formError(mutation.error, SHOWN) && <Notice tone="error">{formError(mutation.error, SHOWN)}</Notice>}

        <div className="form-grid">
          <SelectField label={ar.trips.vehicle} value={vehicleId} onChange={(event) => setVehicleId(event.target.value)} error={fieldError(mutation.error, 'vehicle_id')} required>
            <option value="">{ar.common.choose}</option>
            {trucks.data?.map((vehicle) => (
              <option key={vehicle.id} value={vehicle.id}>
                {vehicle.plate_no} — {ar.shipments.vehicleCategory[vehicle.category]}
              </option>
            ))}
          </SelectField>
          <SelectField label={ar.trips.driver} value={driverId} onChange={(event) => setDriverId(event.target.value)} error={fieldError(mutation.error, 'driver_id')} required>
            <option value="">{ar.common.choose}</option>
            {drivers.data?.map((driver) => (
              <option key={driver.id} value={driver.id}>
                {driver.name}
              </option>
            ))}
          </SelectField>
          <SelectField label={ar.trips.borderPoint} value={borderId} onChange={(event) => setBorderId(event.target.value)} error={fieldError(mutation.error, 'border_point_id')} optional>
            <option value="">{ar.common.choose}</option>
            {borders.data?.map((point) => (
              <option key={point.id} value={point.id}>
                {point.name}
              </option>
            ))}
          </SelectField>
          <SelectField label={ar.trips.clearingAgent} value={clearingId} onChange={(event) => setClearingId(event.target.value)} error={fieldError(mutation.error, 'clearing_agent_id')} optional>
            <option value="">{ar.common.choose}</option>
            {clearing.data?.map((agent) => (
              <option key={agent.id} value={agent.id}>
                {agent.name}
              </option>
            ))}
          </SelectField>
        </div>

        <h2 className="section-title">{ar.trips.pickShipments}</h2>
        {fieldError(mutation.error, 'shipment_ids') && <Notice tone="error">{fieldError(mutation.error, 'shipment_ids')}</Notice>}
        {loadable.isPending && <Spinner />}
        {loadable.data && loadable.data.length === 0 && <Notice>{ar.trips.noShipments}</Notice>}
        {loadable.data && loadable.data.length > 0 && (
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th scope="col" />
                  <th scope="col">{ar.shipments.columns.serial}</th>
                  <th scope="col">{ar.shipments.columns.customer}</th>
                  <th scope="col">{ar.shipments.columns.consignee}</th>
                </tr>
              </thead>
              <tbody>
                {loadable.data.map((shipment) => (
                  <tr key={shipment.id}>
                    <td>
                      <input type="checkbox" aria-label={shipment.serial_no} checked={selected.includes(shipment.id)} onChange={() => toggle(shipment.id)} />
                    </td>
                    <td>
                      <Link to={`/shipments/${shipment.id}`} className="ltr">
                        {shipment.serial_no}
                      </Link>
                    </td>
                    <td>{shipment.customer?.name}</td>
                    <td>{shipment.consignee_name}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <p className="muted">{ar.transfers.selected(selected.length)}</p>

        <h2 className="section-title">{ar.trips.undertaking}</h2>
        <div className="form-grid">
          <TextField label={ar.trips.pickupPoint} value={pickup} onChange={(event) => setPickup(event.target.value)} error={fieldError(mutation.error, 'pickup_point')} optional />
          <TextField label={ar.trips.deliveryPoint} value={delivery} onChange={(event) => setDelivery(event.target.value)} error={fieldError(mutation.error, 'delivery_point')} optional />
          <TextField label={ar.trips.deliveryDays} value={days} onChange={(event) => setDays(event.target.value)} error={fieldError(mutation.error, 'delivery_days')} inputMode="numeric" ltr optional />
          <TextField label={ar.trips.dailyPenalty} value={penalty} onChange={(event) => setPenalty(event.target.value)} error={fieldError(mutation.error, 'daily_penalty')} inputMode="decimal" ltr optional />
          {penalty.trim() !== '' && (
            <SelectField
              label={ar.trips.penaltyCurrency}
              value={penaltyCurrency || String(currencies.data?.[0]?.id ?? '')}
              onChange={(event) => setPenaltyCurrency(event.target.value)}
              error={fieldError(mutation.error, 'currency_id')}
            >
              {currencies.data?.map((currency) => (
                <option key={currency.id} value={currency.id}>
                  {currency.name_ar}
                </option>
              ))}
            </SelectField>
          )}
        </div>

        <TextField
          label={ar.trips.fingerprint}
          type="file"
          accept="image/png,image/jpeg,image/bmp"
          hint={ar.trips.fingerprintHint}
          error={fieldError(mutation.error, 'fingerprint')}
          onChange={(event) => setFingerprint(event.target.files?.[0] ?? null)}
          required
        />

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={!ready}>
            {ar.trips.create}
          </Button>
          <Button variant="ghost" onClick={() => navigate('/trips')}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </>
  );
}
