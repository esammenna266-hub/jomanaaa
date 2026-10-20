import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { ar } from '../../i18n/ar';
import type { Customer } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { saveCustomer } from './api';

const FIELDS = ['name', 'phone', 'passport_no', 'country'];

interface CustomerFormProps {
  customer: Customer | null;
  onClose: () => void;
  /** Called with the saved customer, e.g. so a shipment form can pick the customer it just created. */
  onSaved?: (customer: Customer) => void;
}

export function CustomerForm({ customer, onClose, onSaved }: CustomerFormProps) {
  const queryClient = useQueryClient();
  const [name, setName] = useState(customer?.name ?? '');
  const [phone, setPhone] = useState(customer?.phone ?? '');
  const [passport, setPassport] = useState(customer?.passport_no ?? '');
  const [country, setCountry] = useState<'EG' | 'SD' | ''>(customer?.country ?? '');

  const mutation = useMutation({
    mutationFn: () =>
      saveCustomer(customer?.id ?? null, {
        name: name.trim(),
        phone: phone.trim(),
        passport_no: passport.trim() || null,
        country: country || null,
      }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['customers'] });
      onSaved?.(result.data);
      onClose();
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  return (
    <Modal title={customer ? ar.customers.editTitle : ar.customers.add} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {formError(mutation.error, FIELDS) && <Notice tone="error">{formError(mutation.error, FIELDS)}</Notice>}

        <TextField
          label={ar.customers.name}
          value={name}
          onChange={(event) => setName(event.target.value)}
          error={fieldError(mutation.error, 'name')}
          required
        />
        <TextField
          label={ar.customers.phone}
          value={phone}
          onChange={(event) => setPhone(event.target.value)}
          error={fieldError(mutation.error, 'phone')}
          hint={ar.customers.phoneHint}
          inputMode="tel"
          ltr
          required
        />
        <TextField
          label={ar.customers.passport}
          value={passport}
          onChange={(event) => setPassport(event.target.value)}
          error={fieldError(mutation.error, 'passport_no')}
          ltr
          optional
        />
        <SelectField
          label={ar.customers.country}
          value={country}
          onChange={(event) => setCountry(event.target.value as 'EG' | 'SD' | '')}
          error={fieldError(mutation.error, 'country')}
          optional
        >
          <option value="">{ar.common.choose}</option>
          <option value="EG">{ar.countries.EG}</option>
          <option value="SD">{ar.countries.SD}</option>
        </SelectField>

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
