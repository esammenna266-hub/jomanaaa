import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import { ApiError } from '../../shared/api/client';
import type { Customer, ItemUnit, ShipmentFile } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { formatDateTime } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextareaField, TextField } from '../../shared/ui/Fields';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Spinner } from '../../shared/ui/Spinner';
import { useAllBranches } from '../core/api';
import { useCurrencies } from '../master-data/api';
import { saveShipment, submitShipment, useExpressBusTrips, useShipment, useShipmentOptions, type ShipmentPayload } from './api';
import { CustomerPicker } from './CustomerPicker';
import { emptyItem, ItemsEditor, type ItemRow } from './ItemsEditor';

type PickedCustomer = Pick<Customer, 'id' | 'name' | 'phone'>;

const SHOWN_FIELDS = [
  'customer_id', 'branch_id', 'type', 'bus_trip_id', 'consignee_name', 'consignee_phone', 'destination_id', 'border_point_id',
  'clearing_agent_id', 'proposed_category', 'proposed_body', 'proposed_tons', 'expected_date', 'currency_id',
  'price', 'discount', 'agent_sale_price', 'notes',
];

/** New shipment form, or the edit form for a draft (when :id is in the URL). */
export function ShipmentFormPage() {
  const params = useParams();
  const id = params.id ? Number(params.id) : null;
  const existing = useShipment(id ?? 0);

  if (id === null) {
    return <ShipmentForm initial={null} />;
  }

  if (existing.isPending) {
    return <Spinner />;
  }

  if (existing.isError) {
    return <Notice tone="error">{ar.common.loadFailed}</Notice>;
  }

  if (existing.data.status !== 'draft') {
    return <Navigate to={`/shipments/${id}`} replace />;
  }

  return <ShipmentForm initial={existing.data} />;
}

const text = (value: string | null | undefined) => value ?? '';

function ShipmentForm({ initial }: { initial: ShipmentFile | null }) {
  const { user, scopeOf } = useAuth();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const options = useShipmentOptions();
  const currencies = useCurrencies();
  const isAgent = user?.agent !== null && user?.agent !== undefined;
  const needsBranch = scopeOf('shipment.create') === 'all' && !isAgent;
  const branches = useAllBranches();
  const [type, setType] = useState<'normal' | 'express'>(initial?.type ?? 'normal');
  const busTrips = useExpressBusTrips(type === 'express');

  const [customer, setCustomer] = useState<PickedCustomer | null>(initial?.customer ? { id: initial.customer_id, ...initial.customer } : null);
  const [customerMissing, setCustomerMissing] = useState(false);
  const [branchId, setBranchId] = useState(initial ? String(initial.branch_id) : '');
  const [busTripId, setBusTripId] = useState(initial?.bus_trip_id ? String(initial.bus_trip_id) : '');
  const [consigneeName, setConsigneeName] = useState(text(initial?.consignee_name));
  const [consigneePhone, setConsigneePhone] = useState(text(initial?.consignee_phone));
  const [destinationId, setDestinationId] = useState(initial ? String(initial.destination_id) : '');
  const [borderId, setBorderId] = useState(initial?.border_point_id ? String(initial.border_point_id) : '');
  const [clearingId, setClearingId] = useState(initial?.clearing_agent_id ? String(initial.clearing_agent_id) : '');
  const [category, setCategory] = useState(text(initial?.proposed_category));
  const [body, setBody] = useState(text(initial?.proposed_body));
  const [tons, setTons] = useState(text(initial?.proposed_tons));
  const [expectedDate, setExpectedDate] = useState(text(initial?.expected_date));
  const [currencyId, setCurrencyId] = useState(initial ? String(initial.currency_id) : '');
  const [price, setPrice] = useState(initial && Number(initial.price) > 0 ? initial.price : '');
  const [discount, setDiscount] = useState(initial && Number(initial.discount) > 0 ? initial.discount : '');
  const [salePrice, setSalePrice] = useState(text(initial?.agent_sale_price));
  const [notes, setNotes] = useState(text(initial?.notes));
  const [rows, setRows] = useState<ItemRow[]>(
    initial && initial.items.length > 0
      ? initial.items.map((item) => ({
          key: item.id,
          name: item.name,
          qty: String(Number(item.qty)),
          unit: item.unit as ItemUnit,
          per_pack: item.per_pack ? String(Number(item.per_pack)) : '',
          weight_kg: item.weight_kg ? String(Number(item.weight_kg)) : '',
          notes: text(item.notes),
        }))
      : [emptyItem()],
  );

  // Default to the first currency (EGP) for a new form once the list has loaded.
  const effectiveCurrency = currencyId || String(currencies.data?.[0]?.id ?? '');

  const mutation = useMutation({
    mutationFn: async (submit: boolean) => {
      const payload: ShipmentPayload = {
        customer_id: customer!.id,
        ...(needsBranch && branchId ? { branch_id: Number(branchId) } : {}),
        type,
        // A normal shipment sends nothing: the server lets go of any trip it still held.
        ...(type === 'express' ? { bus_trip_id: busTripId ? Number(busTripId) : null } : {}),
        consignee_name: consigneeName.trim(),
        consignee_phone: consigneePhone.trim(),
        destination_id: destinationId ? Number(destinationId) : null,
        border_point_id: borderId ? Number(borderId) : null,
        clearing_agent_id: clearingId ? Number(clearingId) : null,
        proposed_category: category || null,
        proposed_body: body || null,
        proposed_tons: tons.trim() || null,
        expected_date: expectedDate || null,
        currency_id: effectiveCurrency ? Number(effectiveCurrency) : null,
        price: price.trim() || null,
        discount: discount.trim() || null,
        ...(isAgent ? { agent_sale_price: salePrice.trim() || null } : {}),
        notes: notes.trim() || null,
        items: rows.map((row) => ({
          name: row.name.trim(),
          qty: row.qty.trim(),
          unit: row.unit,
          per_pack: row.per_pack.trim() || null,
          weight_kg: row.weight_kg.trim() || null,
          notes: row.notes.trim() || null,
        })),
      };

      const saved = await saveShipment(initial?.id ?? null, payload);
      let notice: string | null = null;

      if (submit) {
        try {
          await submitShipment(saved.data.id);
        } catch (error) {
          notice = ar.shipments.noteFromSubmit(error instanceof ApiError ? error.message : '');
        }
      }

      return { id: saved.data.id, notice };
    },
    onSuccess: async ({ id, notice }) => {
      await queryClient.invalidateQueries({ queryKey: ['shipments'] });
      await queryClient.invalidateQueries({ queryKey: ['shipment', id] });
      navigate(`/shipments/${id}`, { state: { notice } });
    },
  });

  function submitWith(submit: boolean) {
    if (!customer) {
      setCustomerMissing(true);

      return;
    }
    setCustomerMissing(false);
    mutation.mutate(submit);
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    submitWith(false);
  }

  const error = (field: string) => fieldError(mutation.error, field);
  const topError = formError(mutation.error, (field) => SHOWN_FIELDS.includes(field) || field === 'items' || field.startsWith('items.'));
  const itemError = (path: string) => (mutation.error instanceof ApiError ? mutation.error.fieldError(path) : undefined);

  return (
    <>
      <PageHeader title={initial ? ar.shipments.editTitle : ar.shipments.new} />

      <form onSubmit={onSubmit} noValidate className="shipment-form">
        {topError && <Notice tone="error">{topError}</Notice>}

        <div className="form-grid">
          <CustomerPicker
            value={customer}
            onChange={(picked) => {
              setCustomer(picked);
              setCustomerMissing(false);
            }}
            error={customerMissing ? ar.shipments.customerRequired : error('customer_id')}
          />

          {needsBranch && (
            <SelectField label={ar.shipments.branch} value={branchId} onChange={(event) => setBranchId(event.target.value)} error={error('branch_id')} required>
              <option value="">{ar.common.choose}</option>
              {branches.data?.map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {branch.name}
                </option>
              ))}
            </SelectField>
          )}

          <SelectField label={ar.shipments.kind} value={type} onChange={(event) => setType(event.target.value as 'normal' | 'express')} error={error('type')}>
            <option value="normal">{ar.shipments.type.normal}</option>
            <option value="express">{ar.shipments.type.express}</option>
          </SelectField>

          {type === 'express' && (
            <SelectField label={ar.shipments.busTrip} value={busTripId} onChange={(event) => setBusTripId(event.target.value)} error={error('bus_trip_id')} hint={ar.shipments.busTripHint} optional>
              <option value="">{ar.common.choose}</option>
              {busTrips.data
                // Full trips drop out, except the one this draft already holds a seat on.
                ?.filter((trip) => trip.express_free > 0 || String(trip.id) === busTripId)
                .map((trip) => (
                  <option key={trip.id} value={trip.id}>
                    {ar.shipments.busTripOption(trip.route, formatDateTime(trip.depart_at), trip.express_free)}
                  </option>
                ))}
            </SelectField>
          )}

          <TextField label={ar.shipments.consigneeName} value={consigneeName} onChange={(event) => setConsigneeName(event.target.value)} error={error('consignee_name')} required />
          <TextField
            label={ar.shipments.consigneePhone}
            value={consigneePhone}
            onChange={(event) => setConsigneePhone(event.target.value)}
            error={error('consignee_phone')}
            hint={ar.shipments.consigneePhoneHint}
            inputMode="tel"
            ltr
            required
          />

          <SelectField label={ar.shipments.destination} value={destinationId} onChange={(event) => setDestinationId(event.target.value)} error={error('destination_id')} required>
            <option value="">{ar.common.choose}</option>
            {options.data?.destinations.map((destination) => (
              <option key={destination.id} value={destination.id}>
                {destination.name}
              </option>
            ))}
          </SelectField>
          <SelectField label={ar.shipments.borderPoint} value={borderId} onChange={(event) => setBorderId(event.target.value)} error={error('border_point_id')} optional>
            <option value="">{ar.common.choose}</option>
            {options.data?.border_points.map((point) => (
              <option key={point.id} value={point.id}>
                {point.name}
              </option>
            ))}
          </SelectField>
          <SelectField label={ar.shipments.clearingAgent} value={clearingId} onChange={(event) => setClearingId(event.target.value)} error={error('clearing_agent_id')} optional>
            <option value="">{ar.common.choose}</option>
            {options.data?.clearing_agents.map((agent) => (
              <option key={agent.id} value={agent.id}>
                {agent.name}
              </option>
            ))}
          </SelectField>
          <TextField label={ar.shipments.expectedDate} type="date" value={expectedDate} onChange={(event) => setExpectedDate(event.target.value)} error={error('expected_date')} ltr optional />

          <SelectField label={ar.shipments.proposedVehicle} value={category} onChange={(event) => setCategory(event.target.value)} error={error('proposed_category')} optional>
            <option value="">{ar.common.choose}</option>
            {(['jumbo', 'single', 'trailer'] as const).map((value) => (
              <option key={value} value={value}>
                {ar.shipments.vehicleCategory[value]}
              </option>
            ))}
          </SelectField>
          <SelectField label={ar.shipments.proposedBody} value={body} onChange={(event) => setBody(event.target.value)} error={error('proposed_body')} optional>
            <option value="">{ar.common.choose}</option>
            <option value="closed">{ar.shipments.vehicleBody.closed}</option>
            <option value="open">{ar.shipments.vehicleBody.open}</option>
          </SelectField>
          <TextField label={ar.shipments.proposedTons} value={tons} onChange={(event) => setTons(event.target.value)} error={error('proposed_tons')} inputMode="decimal" ltr optional />
        </div>

        <ItemsEditor rows={rows} onChange={setRows} errorFor={itemError} />

        <div className="form-grid">
          <SelectField label={ar.shipments.currency} value={effectiveCurrency} onChange={(event) => setCurrencyId(event.target.value)} error={error('currency_id')} required>
            {currencies.data?.map((currency) => (
              <option key={currency.id} value={currency.id}>
                {currency.name_ar}
              </option>
            ))}
          </SelectField>
          <TextField label={ar.shipments.price} value={price} onChange={(event) => setPrice(event.target.value)} error={error('price')} inputMode="decimal" ltr optional />
          <TextField label={ar.shipments.discount} value={discount} onChange={(event) => setDiscount(event.target.value)} error={error('discount')} inputMode="decimal" ltr optional />
          {isAgent && (
            <TextField
              label={ar.shipments.agentSalePrice}
              value={salePrice}
              onChange={(event) => setSalePrice(event.target.value)}
              error={error('agent_sale_price')}
              inputMode="decimal"
              ltr
              optional
            />
          )}
        </div>

        <TextareaField label={ar.shipments.notes} value={notes} onChange={(event) => setNotes(event.target.value)} error={error('notes')} optional />

        <div className="form-actions">
          <Button type="submit" variant="secondary" loading={mutation.isPending}>
            {ar.shipments.saveDraft}
          </Button>
          <Button loading={mutation.isPending} onClick={() => submitWith(true)}>
            {ar.shipments.saveAndSubmit}
          </Button>
          <Button variant="ghost" onClick={() => navigate(initial ? `/shipments/${initial.id}` : '/shipments')}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </>
  );
}
