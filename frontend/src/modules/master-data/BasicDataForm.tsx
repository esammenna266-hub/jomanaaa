import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { ar } from '../../i18n/ar';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { CheckboxField, SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { BASIC_DATA, saveBasicRecord, toPayload, type BasicDataKey, type BasicRecord, type FieldValues } from './basicData';

const initialValues = (key: BasicDataKey, record: BasicRecord | null): FieldValues =>
  Object.fromEntries(
    BASIC_DATA[key].fields.map((field) => {
      const value = record?.[field.name];

      return [field.name, field.kind === 'checkbox' ? value === true : value === null || value === undefined ? '' : String(value)];
    }),
  );

export function BasicDataForm({ resource, record, onClose }: { resource: BasicDataKey; record: BasicRecord | null; onClose: () => void }) {
  const queryClient = useQueryClient();
  const fields = BASIC_DATA[resource].fields;
  const labels = ar.basicData.fields as Record<string, string>;
  const [values, setValues] = useState<FieldValues>(() => initialValues(resource, record));

  const mutation = useMutation({
    mutationFn: () => saveBasicRecord(resource, record?.id ?? null, toPayload(resource, values)),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['basic-data', resource] });
      // The same lists feed dropdowns elsewhere (shipment form, trips).
      await Promise.all([queryClient.invalidateQueries({ queryKey: ['lookup'] }), queryClient.invalidateQueries({ queryKey: ['vehicles'] }), queryClient.invalidateQueries({ queryKey: ['drivers'] })]);
      onClose();
    },
  });

  const visible = fields.filter((field) => !field.visible || field.visible(values));
  const isRequired = (field: (typeof fields)[number]) => (typeof field.required === 'function' ? field.required(values) : field.required === true);
  const ready = visible.every((field) => !isRequired(field) || String(values[field.name] ?? '').trim() !== '');
  const set = (name: string, value: string | boolean) => setValues((current) => ({ ...current, [name]: value }));

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  return (
    <Modal title={record ? ar.basicData.editTitle(ar.basicData.single[resource]) : ar.basicData.addTitle(ar.basicData.single[resource])} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {formError(mutation.error, fields.map((field) => field.name)) && <Notice tone="error">{formError(mutation.error, fields.map((field) => field.name))}</Notice>}

        {visible.map((field) => {
          const label = labels[field.name] ?? field.name;
          const error = fieldError(mutation.error, field.name);

          if (field.kind === 'checkbox') {
            return <CheckboxField key={field.name} label={label} checked={values[field.name] === true} onChange={(event) => set(field.name, event.target.checked)} error={error} />;
          }

          if (field.kind === 'select') {
            return (
              <SelectField key={field.name} label={label} value={String(values[field.name] ?? '')} onChange={(event) => set(field.name, event.target.value)} error={error} required={isRequired(field)} optional={!isRequired(field)}>
                <option value="">{ar.common.choose}</option>
                {field.options?.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </SelectField>
            );
          }

          return (
            <TextField
              key={field.name}
              label={label}
              value={String(values[field.name] ?? '')}
              onChange={(event) => set(field.name, event.target.value)}
              error={error}
              required={isRequired(field)}
              optional={!isRequired(field)}
              ltr={field.ltr === true}
              inputMode={field.kind === 'number' ? 'decimal' : undefined}
            />
          );
        })}

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={!ready}>
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
