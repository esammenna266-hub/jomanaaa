import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { ar } from '../../i18n/ar';
import type { Branch } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { CheckboxField, SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { saveBranch } from './api';

const FIELDS = ['name', 'country', 'phone', 'address', 'is_main_warehouse'];

export function BranchForm({ branch, onClose }: { branch: Branch | null; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [name, setName] = useState(branch?.name ?? '');
  const [country, setCountry] = useState<'EG' | 'SD'>(branch?.country ?? 'EG');
  const [phone, setPhone] = useState(branch?.phone ?? '');
  const [address, setAddress] = useState(branch?.address ?? '');
  const [isMain, setIsMain] = useState(branch?.is_main_warehouse ?? false);

  const mutation = useMutation({
    mutationFn: () =>
      saveBranch(branch?.id ?? null, {
        name: name.trim(),
        country,
        phone: phone.trim() || null,
        address: address.trim() || null,
        is_main_warehouse: isMain,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['branches'] });
      onClose();
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  return (
    <Modal title={branch ? ar.branches.editTitle : ar.branches.add} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {formError(mutation.error, FIELDS) && <Notice tone="error">{formError(mutation.error, FIELDS)}</Notice>}

        <TextField
          label={ar.branches.name}
          value={name}
          onChange={(event) => setName(event.target.value)}
          error={fieldError(mutation.error, 'name')}
          required
        />
        <SelectField
          label={ar.branches.country}
          value={country}
          onChange={(event) => setCountry(event.target.value as 'EG' | 'SD')}
          error={fieldError(mutation.error, 'country')}
        >
          <option value="EG">{ar.countries.EG}</option>
          <option value="SD">{ar.countries.SD}</option>
        </SelectField>
        <TextField
          label={ar.branches.phone}
          value={phone}
          onChange={(event) => setPhone(event.target.value)}
          error={fieldError(mutation.error, 'phone')}
          ltr
          optional
        />
        <TextField
          label={ar.branches.address}
          value={address}
          onChange={(event) => setAddress(event.target.value)}
          error={fieldError(mutation.error, 'address')}
          optional
        />
        <CheckboxField
          label={ar.branches.mainWarehouse}
          hint={ar.branches.mainWarehouseHint}
          checked={isMain}
          onChange={(event) => setIsMain(event.target.checked)}
          error={fieldError(mutation.error, 'is_main_warehouse')}
        />

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
