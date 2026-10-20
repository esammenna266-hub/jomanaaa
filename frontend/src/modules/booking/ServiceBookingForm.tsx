import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { Customer } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField, TextareaField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { useCurrencies } from '../master-data/api';
import { CustomerPicker } from '../shipping/CustomerPicker';
import { useTreasuries } from '../shipping/api';
import { createServiceBooking, type ServiceBookingPayload } from './serviceApi';

const SHOWN = ['type', 'customer_id', 'details', 'start_date', 'end_date', 'price', 'currency_id', 'treasury_id'];

export function ServiceBookingForm({ onClose }: { onClose: () => void }) {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const isOffice = user?.agent != null;
  const treasuries = useTreasuries(!isOffice);

  const [type, setType] = useState<'hotel' | 'car'>('hotel');
  const [customer, setCustomer] = useState<Pick<Customer, 'id' | 'name' | 'phone'> | null>(null);
  const [details, setDetails] = useState('');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [price, setPrice] = useState('');
  const [currencyId, setCurrencyId] = useState('');
  const [treasuryId, setTreasuryId] = useState('');

  const effectiveCurrency = currencyId || String(currencies.data?.[0]?.id ?? '');
  const sameCurrency = treasuries.data?.filter((treasury) => String(treasury.currency_id) === effectiveCurrency) ?? [];

  const mutation = useMutation({
    mutationFn: () => {
      const payload: ServiceBookingPayload = {
        type,
        customer_id: customer!.id,
        details: details.trim(),
        price: price.trim(),
        currency_id: Number(effectiveCurrency),
      };
      if (startDate) payload.start_date = startDate;
      if (endDate) payload.end_date = endDate;
      if (!isOffice && treasuryId && sameCurrency.some((treasury) => String(treasury.id) === treasuryId)) payload.treasury_id = Number(treasuryId);

      return createServiceBooking(payload);
    },
    onSuccess: async () => {
      await Promise.all([queryClient.invalidateQueries({ queryKey: ['service-bookings'] }), queryClient.invalidateQueries({ queryKey: ['treasuries'] })]);
      onClose();
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  const err = (field: string) => fieldError(mutation.error, field);
  const ready = customer !== null && details.trim() !== '' && price.trim() !== '';

  return (
    <Modal title={ar.services.newTitle} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {formError(mutation.error, SHOWN) && <Notice tone="error">{formError(mutation.error, SHOWN)}</Notice>}

        <SelectField label={ar.services.type} value={type} onChange={(event) => setType(event.target.value as 'hotel' | 'car')} error={err('type')}>
          <option value="hotel">{ar.services.types.hotel}</option>
          <option value="car">{ar.services.types.car}</option>
        </SelectField>
        <CustomerPicker value={customer} onChange={setCustomer} error={err('customer_id')} />
        <TextareaField label={ar.services.details} hint={ar.services.detailsHint} value={details} onChange={(event) => setDetails(event.target.value)} error={err('details')} required />
        <div className="form-grid">
          <TextField label={ar.services.startDate} type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} error={err('start_date')} ltr optional />
          <TextField label={ar.services.endDate} type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} error={err('end_date')} ltr optional />
          <TextField label={ar.services.price} value={price} onChange={(event) => setPrice(event.target.value)} error={err('price')} inputMode="decimal" ltr required />
          <SelectField label={ar.agents.currency} value={effectiveCurrency} onChange={(event) => setCurrencyId(event.target.value)} error={err('currency_id')}>
            {currencies.data?.map((currency) => (
              <option key={currency.id} value={currency.id}>
                {currency.name_ar}
              </option>
            ))}
          </SelectField>
        </div>

        {isOffice ? (
          <Notice>{ar.services.officeHint}</Notice>
        ) : (
          <SelectField label={ar.services.treasury} hint={ar.services.treasuryHint} value={treasuryId} onChange={(event) => setTreasuryId(event.target.value)} error={err('treasury_id')} optional>
            <option value="">{ar.services.payLater}</option>
            {sameCurrency.map((treasury) => (
              <option key={treasury.id} value={treasury.id}>
                {ar.shipments.treasuryMethod[treasury.method]} — {treasury.currency?.name_ar} ({treasury.branch?.name})
              </option>
            ))}
          </SelectField>
        )}

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={!ready}>
            {ar.services.create}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
