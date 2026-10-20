import {
  useId,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react';
import { ar } from '../../i18n/ar';

interface FieldMeta {
  label: string;
  error?: string | undefined;
  hint?: string;
  optional?: boolean;
}

function describedBy(id: string, error?: string, hint?: string): string | undefined {
  const ids = [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean);

  return ids.length > 0 ? ids.join(' ') : undefined;
}

function Shell({ id, label, error, hint, optional, children }: FieldMeta & { id: string; children: ReactNode }) {
  return (
    <div className="field">
      <label htmlFor={id} className="field__label">
        {label}
        {optional && <span className="muted"> ({ar.common.optional})</span>}
      </label>
      {children}
      {hint && (
        <div id={`${id}-hint`} className="field__hint">
          {hint}
        </div>
      )}
      {error && (
        <div id={`${id}-error`} className="field__error">
          {error}
        </div>
      )}
    </div>
  );
}

type TextFieldProps = FieldMeta & Omit<InputHTMLAttributes<HTMLInputElement>, 'className'> & { ltr?: boolean };

export function TextField({ label, error, hint, optional, ltr = false, ...input }: TextFieldProps) {
  const id = useId();

  return (
    <Shell id={id} label={label} error={error} hint={hint} optional={optional}>
      <input
        id={id}
        className={`input${ltr ? ' ltr' : ''}`}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, error, hint)}
        {...input}
      />
    </Shell>
  );
}

type SelectFieldProps = FieldMeta & Omit<SelectHTMLAttributes<HTMLSelectElement>, 'className'>;

export function SelectField({ label, error, hint, optional, children, ...select }: SelectFieldProps) {
  const id = useId();

  return (
    <Shell id={id} label={label} error={error} hint={hint} optional={optional}>
      <select
        id={id}
        className="input"
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, error, hint)}
        {...select}
      >
        {children}
      </select>
    </Shell>
  );
}

type TextareaFieldProps = FieldMeta & Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'className'>;

export function TextareaField({ label, error, hint, optional, ...textarea }: TextareaFieldProps) {
  const id = useId();

  return (
    <Shell id={id} label={label} error={error} hint={hint} optional={optional}>
      <textarea
        id={id}
        className="input"
        rows={3}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, error, hint)}
        {...textarea}
      />
    </Shell>
  );
}

type CheckboxFieldProps = { label: string; hint?: string; error?: string | undefined } & Omit<
  InputHTMLAttributes<HTMLInputElement>,
  'className' | 'type'
>;

export function CheckboxField({ label, hint, error, ...input }: CheckboxFieldProps) {
  const id = useId();

  return (
    <div className="field field--check">
      <input id={id} type="checkbox" aria-describedby={describedBy(id, error, hint)} {...input} />
      <label htmlFor={id}>{label}</label>
      {hint && (
        <div id={`${id}-hint`} className="field__hint">
          {hint}
        </div>
      )}
      {error && (
        <div id={`${id}-error`} className="field__error">
          {error}
        </div>
      )}
    </div>
  );
}
