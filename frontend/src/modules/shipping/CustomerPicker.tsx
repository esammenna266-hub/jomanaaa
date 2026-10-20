import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import { api } from '../../shared/api/client';
import type { Customer, Paginated } from '../../shared/api/types';
import { useDebounce } from '../../shared/hooks/useDebounce';
import { Button } from '../../shared/ui/Button';
import { TextField } from '../../shared/ui/Fields';
import { CustomerForm } from '../master-data/CustomerForm';

interface CustomerPickerProps {
  value: Pick<Customer, 'id' | 'name' | 'phone'> | null;
  onChange: (customer: Pick<Customer, 'id' | 'name' | 'phone'> | null) => void;
  error?: string | undefined;
}

/** Finds a customer by phone or name (phone search is the fast path at the counter), or creates one on the spot. */
export function CustomerPicker({ value, onChange, error }: CustomerPickerProps) {
  const { can } = useAuth();
  const [term, setTerm] = useState('');
  const q = useDebounce(term.trim());
  const [creating, setCreating] = useState(false);

  const results = useQuery({
    queryKey: ['customers', 'pick', q],
    queryFn: () => api<Paginated<Customer>>('/customers', { query: { q, active: 1, per_page: 8 } }),
    enabled: q.length >= 2 && value === null,
  });

  if (value) {
    return (
      <div className="field">
        <span className="field__label">{ar.shipments.customer}</span>
        <div className="picker__chosen">
          <strong>{value.name}</strong> <span className="ltr muted">{value.phone}</span>
          <Button variant="ghost" onClick={() => onChange(null)}>
            {ar.shipments.customerChange}
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div>
      <TextField
        label={ar.shipments.customer}
        value={term}
        onChange={(event) => setTerm(event.target.value)}
        placeholder={ar.shipments.customerSearch}
        error={error}
        autoComplete="off"
      />
      {results.data && results.data.data.length > 0 && (
        <ul className="picker__results">
          {results.data.data.map((customer) => (
            <li key={customer.id}>
              <button type="button" onClick={() => onChange(customer)}>
                <strong>{customer.name}</strong> <span className="ltr muted">{customer.phone}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {can('customer.create') && (
        <Button variant="ghost" onClick={() => setCreating(true)}>
          + {ar.shipments.customerNew}
        </Button>
      )}
      {creating && <CustomerForm customer={null} onClose={() => setCreating(false)} onSaved={(customer) => onChange(customer)} />}
    </div>
  );
}
