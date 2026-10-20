import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { CargoTrip, TripStage } from '../../shared/api/types';
import { formatDateTime, formatMoney } from '../../shared/format';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { ReasonDialog } from '../../shared/ui/ReasonDialog';
import { Spinner } from '../../shared/ui/Spinner';
import { printDocument, type DocumentType } from '../documents/api';
import { currencyName, useCurrencies } from '../master-data/api';
import { ShipmentStatusBadge } from '../shipping/ShipmentStatusBadge';
import { advanceTrip, cancelTrip, replaceFingerprint, TRIP_STAGES, useTrip } from './api';
import { TripDelays } from './TripDelays';
import { TripExpenses } from './TripExpenses';
import { TripStageBadge } from './TripsPage';

type Dialog = 'stage' | 'cancel' | 'fingerprint' | null;

export function TripFilePage() {
  const id = Number(useParams().id);
  const query = useTrip(id);

  if (query.isPending) {
    return <Spinner />;
  }

  if (query.isError) {
    return <Notice tone="error">{query.error.message}</Notice>;
  }

  return <TripFile trip={query.data} />;
}

function TripFile({ trip }: { trip: CargoTrip }) {
  const { can } = useAuth();
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const [dialog, setDialog] = useState<Dialog>(null);

  const canManage = can('cargo_trip.manage');
  const canTrack = can('tracking.update');
  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['trip', trip.id] }),
      queryClient.invalidateQueries({ queryKey: ['trips'] }),
      queryClient.invalidateQueries({ queryKey: ['shipments'] }),
    ]);
  const print = useMutation({ mutationFn: ({ type, subjectId }: { type: DocumentType; subjectId: number }) => printDocument(type, subjectId) });

  const currentIndex = TRIP_STAGES.indexOf(trip.stage);
  const laterStages = trip.stage === 'cancelled' ? [] : TRIP_STAGES.slice(currentIndex + 1);
  const eventAt = (stage: string) => trip.events.find((event) => event.stage === stage)?.happened_at;
  const undertaking = trip.undertaking;

  return (
    <>
      <PageHeader title={ar.trips.file(trip.serial_no)}>
        <div className="actions-bar">
          <Button variant="secondary" loading={print.isPending} onClick={() => print.mutate({ type: 'manifest', subjectId: trip.id })}>
            {ar.trips.printManifest}
          </Button>
          {undertaking && (
            <Button variant="secondary" disabled={print.isPending} onClick={() => print.mutate({ type: 'driver_undertaking', subjectId: undertaking.id })}>
              {ar.trips.printUndertaking}
            </Button>
          )}
          {canTrack && laterStages.length > 0 && <Button onClick={() => setDialog('stage')}>{ar.trips.updateStage}</Button>}
          {canManage && trip.stage === 'loaded' && (
            <>
              <Button variant="secondary" onClick={() => setDialog('fingerprint')}>
                {ar.trips.replaceFingerprint}
              </Button>
              <Button variant="danger" onClick={() => setDialog('cancel')}>
                {ar.trips.cancelTrip}
              </Button>
            </>
          )}
        </div>
      </PageHeader>

      {print.isError && <Notice tone="error">{print.error.message}</Notice>}

      <div className="file-head">
        <TripStageBadge stage={trip.stage} />
        <span className="muted">
          {ar.trips.departedAt}: {formatDateTime(trip.departed_at)}
        </span>
        <span className="muted">
          {ar.trips.arrivedAt}: {formatDateTime(trip.arrived_at)}
        </span>
      </div>

      <div className="grid-2">
        <section className="card">
          <dl className="dl">
            <dt>{ar.trips.vehicle}</dt>
            <dd className="ltr">{trip.vehicle?.plate_no}</dd>
            <dt>{ar.trips.driver}</dt>
            <dd>
              {trip.driver?.name} <span className="ltr muted">{trip.driver?.phone}</span>
            </dd>
            <dt>{ar.trips.borderPoint}</dt>
            <dd>{trip.border_point?.name ?? '—'}</dd>
            <dt>{ar.trips.clearingAgent}</dt>
            <dd>{trip.clearing_agent?.name ?? '—'}</dd>
          </dl>
        </section>

        {undertaking && (
          <section className="card">
            <h2>{ar.trips.undertakingTitle}</h2>
            <dl className="dl">
              <dt>{ar.trips.undertakingNo}</dt>
              <dd className="ltr">{undertaking.serial_no}</dd>
              <dt>{ar.trips.pickupPoint}</dt>
              <dd>{undertaking.pickup_point ?? '—'}</dd>
              <dt>{ar.trips.deliveryPoint}</dt>
              <dd>{undertaking.delivery_point ?? '—'}</dd>
              <dt>{ar.trips.deliveryDays}</dt>
              <dd className="ltr">{undertaking.delivery_days ?? '—'}</dd>
              <dt>{ar.trips.dailyPenalty}</dt>
              <dd>
                {undertaking.daily_penalty ? (
                  <>
                    <span className="ltr">{formatMoney(undertaking.daily_penalty)}</span> {undertaking.currency_id ? currencyName(currencies.data, undertaking.currency_id) : ''}
                  </>
                ) : (
                  '—'
                )}
              </dd>
            </dl>
            {trip.fingerprint_url && (
              <figure className="fingerprint">
                <img src={trip.fingerprint_url} alt={ar.trips.fingerprintTitle} />
                <figcaption className="muted">{ar.trips.fingerprintTitle}</figcaption>
              </figure>
            )}
          </section>
        )}
      </div>

      <h2 className="section-title">{ar.trips.eventsTitle}</h2>
      <ol className="timeline">
        {TRIP_STAGES.map((stage, index) => {
          const reached = trip.stage !== 'cancelled' && index <= currentIndex;
          const at = eventAt(stage);

          return (
            <li key={stage} className={`timeline__item${reached ? ' timeline__item--done' : ''}${index === currentIndex && trip.stage !== 'cancelled' ? ' timeline__item--current' : ''}`}>
              <strong>{ar.trips.stage[stage]}</strong>
              <span className="muted">{at ? formatDateTime(at) : ''}</span>
            </li>
          );
        })}
        {trip.stage === 'cancelled' && (
          <li className="timeline__item timeline__item--cancelled">
            <strong>{ar.trips.stage.cancelled}</strong>
            <span className="muted">{trip.events.find((event) => event.stage === 'cancelled')?.notes}</span>
          </li>
        )}
      </ol>

      <h2 className="section-title">{ar.trips.shipmentsTitle}</h2>
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th scope="col">{ar.shipments.columns.serial}</th>
              <th scope="col">{ar.shipments.columns.consignee}</th>
              <th scope="col">{ar.shipments.columns.status}</th>
              <th scope="col" />
            </tr>
          </thead>
          <tbody>
            {trip.shipments.map((shipment) => (
              <tr key={shipment.id}>
                <td>
                  <Link to={`/shipments/${shipment.id}`} className="ltr">
                    {shipment.serial_no}
                  </Link>
                </td>
                <td>{shipment.consignee_name}</td>
                <td>
                  <ShipmentStatusBadge status={shipment.status} />
                </td>
                <td>
                  <Button
                    variant="ghost"
                    aria-label={`${ar.trips.printWaybill} ${shipment.serial_no}`}
                    disabled={print.isPending}
                    onClick={() => print.mutate({ type: 'waybill', subjectId: shipment.id })}
                  >
                    {ar.trips.printWaybill}
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {can('expense.manage') && (
        <>
          <TripExpenses trip={trip} />
          <TripDelays trip={trip} />
        </>
      )}

      <p>
        <Link to="/trips">← {ar.trips.title}</Link>
      </p>

      {dialog === 'stage' && <StageDialog trip={trip} stages={laterStages} onDone={refresh} onClose={() => setDialog(null)} />}
      {dialog === 'fingerprint' && <FingerprintDialog trip={trip} onDone={refresh} onClose={() => setDialog(null)} />}
      {dialog === 'cancel' && (
        <ReasonDialog
          title={ar.trips.cancelTrip}
          label={ar.trips.cancelReason}
          confirmLabel={ar.trips.cancelTrip}
          intro={ar.trips.cancelHint}
          danger
          onConfirm={async (reason) => {
            await cancelTrip(trip.id, reason);
            await refresh();
          }}
          onClose={() => setDialog(null)}
        />
      )}
    </>
  );
}

function StageDialog({ trip, stages, onDone, onClose }: { trip: CargoTrip; stages: TripStage[]; onDone: () => Promise<unknown>; onClose: () => void }) {
  // The next stage is the usual choice; later ones can be picked when a step was skipped.
  const [stage, setStage] = useState<TripStage>(stages[0] ?? 'completed');
  const [notes, setNotes] = useState('');

  const mutation = useMutation({
    mutationFn: () => advanceTrip(trip.id, stage, notes.trim() || null),
    onSuccess: async () => {
      await onDone();
      onClose();
    },
  });

  return (
    <Modal title={ar.trips.updateStage} onClose={onClose}>
      <form
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          mutation.mutate();
        }}
        noValidate
      >
        {formError(mutation.error, ['stage', 'notes']) && <Notice tone="error">{formError(mutation.error, ['stage', 'notes'])}</Notice>}
        <SelectField label={ar.trips.nextStage} value={stage} onChange={(event) => setStage(event.target.value as TripStage)} error={fieldError(mutation.error, 'stage')}>
          {stages.map((value) => (
            <option key={value} value={value}>
              {ar.trips.stage[value]}
            </option>
          ))}
        </SelectField>
        <TextField label={ar.trips.notes} value={notes} onChange={(event) => setNotes(event.target.value)} error={fieldError(mutation.error, 'notes')} optional />
        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending}>
            {ar.common.save}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function FingerprintDialog({ trip, onDone, onClose }: { trip: CargoTrip; onDone: () => Promise<unknown>; onClose: () => void }) {
  const [file, setFile] = useState<File | null>(null);

  const mutation = useMutation({
    mutationFn: () => replaceFingerprint(trip.id, file!),
    onSuccess: async () => {
      await onDone();
      onClose();
    },
  });

  return (
    <Modal title={ar.trips.replaceFingerprint} onClose={onClose}>
      <form
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          mutation.mutate();
        }}
        noValidate
      >
        {formError(mutation.error, ['fingerprint']) && <Notice tone="error">{formError(mutation.error, ['fingerprint'])}</Notice>}
        <TextField
          label={ar.trips.fingerprint}
          type="file"
          accept="image/png,image/jpeg,image/bmp"
          hint={ar.trips.fingerprintHint}
          error={fieldError(mutation.error, 'fingerprint')}
          onChange={(event) => setFile(event.target.files?.[0] ?? null)}
          required
        />
        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={file === null}>
            {ar.common.save}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
