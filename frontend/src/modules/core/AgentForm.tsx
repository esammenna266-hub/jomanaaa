import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { ar } from '../../i18n/ar';
import type { Agent } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { saveAgent } from './agentsApi';

const FIELDS = ['name', 'country', 'phone'];

export function AgentForm({ agent, onClose }: { agent: Agent | null; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [name, setName] = useState(agent?.name ?? '');
  const [country, setCountry] = useState<string>(agent?.country ?? 'SD');
  const [phone, setPhone] = useState(agent?.phone ?? '');

  const mutation = useMutation({
    mutationFn: () =>
      saveAgent(agent?.id ?? null, {
        name: name.trim(),
        country: country === '' ? null : (country as 'EG' | 'SD'),
        phone: phone.trim() || null,
      }),
    onSuccess: async () => {
      await Promise.all([queryClient.invalidateQueries({ queryKey: ['agents'] }), queryClient.invalidateQueries({ queryKey: ['agent'] })]);
      onClose();
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  return (
    <Modal title={agent ? ar.agents.editTitle : ar.agents.add} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {formError(mutation.error, FIELDS) && <Notice tone="error">{formError(mutation.error, FIELDS)}</Notice>}

        <TextField label={ar.agents.name} value={name} onChange={(event) => setName(event.target.value)} error={fieldError(mutation.error, 'name')} required />
        <SelectField label={ar.agents.country} value={country} onChange={(event) => setCountry(event.target.value)} error={fieldError(mutation.error, 'country')}>
          <option value="EG">{ar.countries.EG}</option>
          <option value="SD">{ar.countries.SD}</option>
        </SelectField>
        <TextField label={ar.agents.phone} value={phone} onChange={(event) => setPhone(event.target.value)} error={fieldError(mutation.error, 'phone')} ltr optional />

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={name.trim() === ''}>
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
