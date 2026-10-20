import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Spinner } from '../../shared/ui/Spinner';
import { useAllBranches } from '../core/api';
import { createTransfer, useReadyForTransfer } from './transfersApi';

const SHOWN = ['from_branch_id', 'vehicle_text', 'driver_text', 'driver_phone', 'shipments'];

export function TransferNewPage() {
  const { scopeOf } = useAuth();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const needsBranch = scopeOf('transfer.create') === 'all';
  const branches = useAllBranches();

  const [branchId, setBranchId] = useState('');
  const [vehicle, setVehicle] = useState('');
  const [driver, setDriver] = useState('');
  const [phone, setPhone] = useState('');
  // shipment id -> optional sent count typed by the employee; presence of the key means "selected"
  const [selected, setSelected] = useState<Record<number, string>>({});
  const [nothingPicked, setNothingPicked] = useState(false);

  const branchReady = !needsBranch || branchId !== '';
  const ready = useReadyForTransfer(needsBranch && branchId ? Number(branchId) : null, branchReady);

  const mutation = useMutation({
    mutationFn: () =>
      createTransfer({
        ...(needsBranch ? { from_branch_id: Number(branchId) } : {}),
        shipments: Object.entries(selected).map(([id, count]) => ({ shipment_id: Number(id), ...(count.trim() ? { sent_count: count.trim() } : {}) })),
        vehicle_text: vehicle.trim() || null,
        driver_text: driver.trim() || null,
        driver_phone: phone.trim() || null,
      }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['transfers'] });
      await queryClient.invalidateQueries({ queryKey: ['shipments'] });
      navigate(`/transfers/${result.data.id}`);
    },
  });

  function toggle(id: number) {
    const next = { ...selected };
    if (id in next) {
      delete next[id];
    } else {
      next[id] = '';
    }
    setSelected(next);
    setNothingPicked(false);
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    if (Object.keys(selected).length === 0) {
      setNothingPicked(true);

      return;
    }
    mutation.mutate();
  }

  return (
    <>
      <PageHeader title={ar.transfers.newTitle} />

      <form onSubmit={onSubmit} noValidate className="shipment-form">
        {formError(mutation.error, SHOWN) && <Notice tone="error">{formError(mutation.error, SHOWN)}</Notice>}

        {needsBranch && (
          <SelectField label={ar.transfers.branch} value={branchId} onChange={(event) => { setBranchId(event.target.value); setSelected({}); }} error={fieldError(mutation.error, 'from_branch_id')} required>
            <option value="">{ar.common.choose}</option>
            {branches.data?.map((branch) => (
              <option key={branch.id} value={branch.id}>
                {branch.name}
              </option>
            ))}
          </SelectField>
        )}

        <h2 className="section-title">{ar.transfers.pickShipments}</h2>
        {(nothingPicked || fieldError(mutation.error, 'shipments')) && <Notice tone="error">{nothingPicked ? ar.transfers.pickAtLeastOne : fieldError(mutation.error, 'shipments')}</Notice>}

        {branchReady && ready.isPending && <Spinner />}
        {branchReady && ready.data && ready.data.length === 0 && <Notice>{ar.transfers.noShipments}</Notice>}
        {ready.data && ready.data.length > 0 && (
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th scope="col" />
                  <th scope="col">{ar.shipments.columns.serial}</th>
                  <th scope="col">{ar.shipments.columns.customer}</th>
                  <th scope="col">{ar.shipments.columns.consignee}</th>
                  <th scope="col">{ar.transfers.sentCount}</th>
                </tr>
              </thead>
              <tbody>
                {ready.data.map((shipment) => {
                  const isSelected = shipment.id in selected;

                  return (
                    <tr key={shipment.id}>
                      <td>
                        <input type="checkbox" aria-label={shipment.serial_no} checked={isSelected} onChange={() => toggle(shipment.id)} />
                      </td>
                      <td>
                        <Link to={`/shipments/${shipment.id}`} className="ltr">
                          {shipment.serial_no}
                        </Link>
                      </td>
                      <td>{shipment.customer?.name}</td>
                      <td>{shipment.consignee_name}</td>
                      <td>
                        <input
                          className="input ltr"
                          inputMode="decimal"
                          aria-label={`${ar.transfers.sentCount} ${shipment.serial_no}`}
                          placeholder={ar.transfers.sentCountAuto}
                          disabled={!isSelected}
                          value={selected[shipment.id] ?? ''}
                          onChange={(event) => setSelected({ ...selected, [shipment.id]: event.target.value })}
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        <p className="muted">{ar.transfers.selected(Object.keys(selected).length)}</p>

        <div className="form-grid">
          <TextField label={ar.transfers.vehicle} value={vehicle} onChange={(event) => setVehicle(event.target.value)} error={fieldError(mutation.error, 'vehicle_text')} optional />
          <TextField label={ar.transfers.driver} value={driver} onChange={(event) => setDriver(event.target.value)} error={fieldError(mutation.error, 'driver_text')} optional />
          <TextField label={ar.transfers.driverPhone} value={phone} onChange={(event) => setPhone(event.target.value)} error={fieldError(mutation.error, 'driver_phone')} inputMode="tel" ltr optional />
        </div>

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending}>
            {ar.transfers.create}
          </Button>
          <Button variant="ghost" onClick={() => navigate('/transfers')}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </>
  );
}
