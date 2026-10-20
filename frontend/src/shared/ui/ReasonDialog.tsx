import { useState, type FormEvent } from 'react';
import { ar } from '../../i18n/ar';
import { formError } from '../forms/errors';
import { Button } from './Button';
import { TextField } from './Fields';
import { Modal } from './Modal';
import { Notice } from './Notice';

interface ReasonDialogProps {
  title: string;
  label: string;
  confirmLabel: string;
  intro?: string;
  danger?: boolean;
  onConfirm: (value: string) => Promise<unknown>;
  onClose: () => void;
}

/** One required text answer, then a confirm: used to cancel, reject and deliver. */
export function ReasonDialog({ title, label, confirmLabel, intro, danger = false, onConfirm, onClose }: ReasonDialogProps) {
  const [value, setValue] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await onConfirm(value.trim());
      onClose();
    } catch (caught) {
      setError(caught);
      setSubmitting(false);
    }
  }

  return (
    <Modal title={title} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {intro && <Notice>{intro}</Notice>}
        {formError(error, () => false) && <Notice tone="error">{formError(error, () => false)}</Notice>}
        <TextField label={label} value={value} onChange={(event) => setValue(event.target.value)} required />
        <div className="form-actions">
          <Button type="submit" variant={danger ? 'danger' : 'primary'} loading={submitting} disabled={value.trim() === ''}>
            {confirmLabel}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
