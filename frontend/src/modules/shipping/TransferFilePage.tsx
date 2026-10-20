import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type KeyboardEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { Transfer } from '../../shared/api/types';
import { formatDateTime } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Spinner } from '../../shared/ui/Spinner';
import { TransferStatusBadge } from './TransfersPage';
import { receiveTransfer, useTransfer } from './transfersApi';

const num = (value: string | number) => String(Number(value));

function differenceText(difference: number): string {
  if (difference === 0) {
    return '0';
  }

  return difference < 0 ? `${difference} (${ar.transfers.shortage})` : `+${difference} (${ar.transfers.overage})`;
}

export function TransferFilePage() {
  const id = Number(useParams().id);
  const query = useTransfer(id);

  if (query.isPending) {
    return <Spinner />;
  }

  if (query.isError) {
    return <Notice tone="error">{query.error.message}</Notice>;
  }

  return <TransferFile transfer={query.data} />;
}

function TransferFile({ transfer }: { transfer: Transfer }) {
  const { can } = useAuth();
  const queryClient = useQueryClient();
  const canReceive = can('transfer.confirm_receipt') && transfer.status === 'sent';

  // shipment id -> the count typed or scanned at the warehouse
  const [counts, setCounts] = useState<Record<number, string>>({});
  const [scan, setScan] = useState('');
  const [scanNote, setScanNote] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);

  const mutation = useMutation({
    mutationFn: () => receiveTransfer(transfer.id, transfer.items.map((item) => ({ shipment_id: item.shipment_id, received_count: counts[item.shipment_id] ?? '' }))),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['transfer', transfer.id] }),
        queryClient.invalidateQueries({ queryKey: ['transfers'] }),
        queryClient.invalidateQueries({ queryKey: ['shipments'] }),
      ]);
    },
  });

  const allCounted = transfer.items.every((item) => (counts[item.shipment_id] ?? '').trim() !== '');
  const differing = transfer.items.filter((item) => counts[item.shipment_id] !== undefined && counts[item.shipment_id] !== '' && Number(counts[item.shipment_id]) !== Number(item.sent_count));

  /** A scanned shipment code confirms that shipment arrived with exactly the sent count. */
  function onScan(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key !== 'Enter' || scan.trim() === '') {
      return;
    }
    event.preventDefault();
    const code = scan.trim().toUpperCase();
    const line = transfer.items.find((item) => item.shipment?.tracking_code === code || item.shipment?.serial_no === scan.trim());

    if (line) {
      setCounts((current) => ({ ...current, [line.shipment_id]: num(line.sent_count) }));
      setScanNote({ tone: 'success', text: ar.transfers.scanned(line.shipment?.serial_no ?? '') });
    } else {
      setScanNote({ tone: 'error', text: ar.transfers.scanUnknown });
    }
    setScan('');
  }

  return (
    <>
      <PageHeader title={ar.transfers.file(transfer.serial_no)}>
        <TransferStatusBadge status={transfer.status} />
      </PageHeader>

      {transfer.has_difference && <Notice tone="error">{ar.transfers.differenceAlert}</Notice>}

      <div className="grid-2">
        <section className="card">
          <dl className="dl">
            <dt>{ar.transfers.barcode}</dt>
            <dd>
              <strong className="barcode ltr">{transfer.barcode}</strong>
            </dd>
            <dt>{ar.transfers.from}</dt>
            <dd>{transfer.from_branch?.name}</dd>
            <dt>{ar.transfers.to}</dt>
            <dd>{transfer.to_branch?.name}</dd>
            <dt>{ar.transfers.carrier}</dt>
            <dd>
              {[transfer.vehicle_text, transfer.driver_text].filter(Boolean).join(' — ') || '—'} <span className="ltr muted">{transfer.driver_phone}</span>
            </dd>
            <dt>{ar.transfers.sentAt}</dt>
            <dd>{formatDateTime(transfer.sent_at)}</dd>
            {transfer.received_at && (
              <>
                <dt>{ar.transfers.receivedAt}</dt>
                <dd>{formatDateTime(transfer.received_at)}</dd>
              </>
            )}
          </dl>
        </section>
      </div>

      {canReceive && (
        <section className="card scan-panel">
          <p className="muted">{ar.transfers.scanHint}</p>
          <input
            className="input ltr"
            aria-label={ar.transfers.scanShipment}
            placeholder={ar.transfers.scanShipment}
            value={scan}
            onChange={(event) => setScan(event.target.value)}
            onKeyDown={onScan}
            autoFocus
          />
          {scanNote && <Notice tone={scanNote.tone}>{scanNote.text}</Notice>}
        </section>
      )}

      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th scope="col">{ar.transfers.lines.shipment}</th>
              <th scope="col">{ar.transfers.lines.customer}</th>
              <th scope="col">{ar.transfers.lines.consignee}</th>
              <th scope="col">{ar.transfers.lines.sent}</th>
              <th scope="col">{ar.transfers.lines.received}</th>
              <th scope="col">{ar.transfers.lines.difference}</th>
            </tr>
          </thead>
          <tbody>
            {transfer.items.map((item) => {
              const typed = counts[item.shipment_id];
              const difference = canReceive ? (typed !== undefined && typed !== '' ? Number(typed) - Number(item.sent_count) : null) : item.difference === null ? null : Number(item.difference);

              return (
                <tr key={item.id} className={difference ? 'row--alert' : undefined}>
                  <td>
                    <Link to={`/shipments/${item.shipment_id}`} className="ltr">
                      {item.shipment?.serial_no}
                    </Link>
                  </td>
                  <td>{item.shipment?.customer?.name}</td>
                  <td>{item.shipment?.consignee_name}</td>
                  <td className="ltr">{num(item.sent_count)}</td>
                  <td>
                    {canReceive ? (
                      <input
                        className="input ltr"
                        inputMode="decimal"
                        aria-label={`${ar.transfers.lines.received} ${item.shipment?.serial_no}`}
                        value={typed ?? ''}
                        onChange={(event) => setCounts({ ...counts, [item.shipment_id]: event.target.value })}
                      />
                    ) : (
                      <span className="ltr">{item.received_count === null ? '—' : num(item.received_count)}</span>
                    )}
                  </td>
                  <td className={difference ? 'text-danger' : undefined}>
                    <strong className="ltr">{difference === null ? '—' : differenceText(difference)}</strong>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {canReceive && (
        <>
          {differing.length > 0 && <Notice tone="error">{ar.transfers.diffWarning(differing.length)}</Notice>}
          {mutation.isError && <Notice tone="error">{mutation.error.message}</Notice>}
          <div className="form-actions">
            <Button loading={mutation.isPending} disabled={!allCounted} onClick={() => mutation.mutate()}>
              {ar.transfers.confirmReceive}
            </Button>
            <Button
              variant="secondary"
              onClick={() => setCounts(Object.fromEntries(transfer.items.map((item) => [item.shipment_id, num(item.sent_count)])))}
            >
              {ar.transfers.receiveAll}
            </Button>
          </div>
          {!allCounted && <p className="muted">{ar.transfers.allCounted}</p>}
        </>
      )}
    </>
  );
}
