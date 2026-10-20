import { ar } from '../../i18n/ar';
import type { ItemUnit } from '../../shared/api/types';
import { Button } from '../../shared/ui/Button';

export interface ItemRow {
  key: number;
  name: string;
  qty: string;
  unit: ItemUnit;
  per_pack: string;
  weight_kg: string;
  notes: string;
}

let nextKey = 1;

export const emptyItem = (): ItemRow => ({ key: nextKey++, name: '', qty: '', unit: 'carton', per_pack: '', weight_kg: '', notes: '' });

const UNITS = Object.keys(ar.shipments.units) as ItemUnit[];

interface ItemsEditorProps {
  rows: ItemRow[];
  onChange: (rows: ItemRow[]) => void;
  /** Server validation messages keyed like "items.0.name". */
  errorFor: (path: string) => string | undefined;
}

export function ItemsEditor({ rows, onChange, errorFor }: ItemsEditorProps) {
  const update = (key: number, patch: Partial<ItemRow>) => onChange(rows.map((row) => (row.key === key ? { ...row, ...patch } : row)));

  return (
    <fieldset className="items">
      <legend>{ar.shipments.items}</legend>
      {errorFor('items') && <div className="field__error">{errorFor('items')}</div>}

      <div className="table-wrap">
        <table className="table items__table">
          <thead>
            <tr>
              <th scope="col">{ar.shipments.itemName}</th>
              <th scope="col">{ar.shipments.itemQty}</th>
              <th scope="col">{ar.shipments.itemUnit}</th>
              <th scope="col">{ar.shipments.itemPerPack}</th>
              <th scope="col">{ar.shipments.itemWeight}</th>
              <th scope="col">{ar.shipments.itemNotes}</th>
              <th scope="col" />
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => {
              const label = (name: string) => `${name} ${index + 1}`;
              const cell = (field: string) => errorFor(`items.${index}.${field}`);

              return (
                <tr key={row.key}>
                  <td>
                    <input
                      className="input"
                      aria-label={label(ar.shipments.itemName)}
                      aria-invalid={cell('name') ? true : undefined}
                      value={row.name}
                      onChange={(event) => update(row.key, { name: event.target.value })}
                    />
                    {cell('name') && <div className="field__error">{cell('name')}</div>}
                  </td>
                  <td>
                    <input
                      className="input ltr"
                      inputMode="decimal"
                      aria-label={label(ar.shipments.itemQty)}
                      aria-invalid={cell('qty') ? true : undefined}
                      value={row.qty}
                      onChange={(event) => update(row.key, { qty: event.target.value })}
                    />
                    {cell('qty') && <div className="field__error">{cell('qty')}</div>}
                  </td>
                  <td>
                    <select
                      className="input"
                      aria-label={label(ar.shipments.itemUnit)}
                      value={row.unit}
                      onChange={(event) => update(row.key, { unit: event.target.value as ItemUnit })}
                    >
                      {UNITS.map((unit) => (
                        <option key={unit} value={unit}>
                          {ar.shipments.units[unit]}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td>
                    <input
                      className="input ltr"
                      inputMode="decimal"
                      aria-label={label(ar.shipments.itemPerPack)}
                      value={row.per_pack}
                      onChange={(event) => update(row.key, { per_pack: event.target.value })}
                    />
                  </td>
                  <td>
                    <input
                      className="input ltr"
                      inputMode="decimal"
                      aria-label={label(ar.shipments.itemWeight)}
                      value={row.weight_kg}
                      onChange={(event) => update(row.key, { weight_kg: event.target.value })}
                    />
                  </td>
                  <td>
                    <input
                      className="input"
                      aria-label={label(ar.shipments.itemNotes)}
                      value={row.notes}
                      onChange={(event) => update(row.key, { notes: event.target.value })}
                    />
                  </td>
                  <td>
                    {rows.length > 1 && (
                      <Button variant="ghost" aria-label={label(ar.shipments.removeItem)} onClick={() => onChange(rows.filter((r) => r.key !== row.key))}>
                        ×
                      </Button>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <Button variant="secondary" onClick={() => onChange([...rows, emptyItem()])}>
        + {ar.shipments.addItem}
      </Button>
    </fieldset>
  );
}
