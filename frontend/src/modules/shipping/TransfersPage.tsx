import { useEffect, useState, type KeyboardEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { Transfer, TransferStatus } from '../../shared/api/types';
import { ApiError } from '../../shared/api/client';
import { formatDateTime } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Pagination } from '../../shared/ui/Pagination';
import { lookupTransfer, useTransfers } from './transfersApi';

const TONE: Record<TransferStatus, string> = { draft: 'muted', sent: 'info', received: 'ok', received_with_diff: 'danger' };

export function TransferStatusBadge({ status }: { status: TransferStatus }) {
  return <span className={`badge badge--${TONE[status]}`}>{ar.transfers.status[status]}</span>;
}

export function TransfersPage() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [scan, setScan] = useState('');
  const [scanError, setScanError] = useState<string | null>(null);

  useEffect(() => setPage(1), [status]);

  const transfers = useTransfers(page, status);

  async function onScan(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key !== 'Enter' || scan.trim() === '') {
      return;
    }
    event.preventDefault();
    setScanError(null);
    try {
      const transfer = await lookupTransfer(scan.trim());
      navigate(`/transfers/${transfer.id}`);
    } catch (error) {
      setScanError(error instanceof ApiError && error.status === 404 ? ar.transfers.scanNotFound : error instanceof Error ? error.message : ar.common.loadFailed);
      setScan('');
    }
  }

  const columns: Column<Transfer>[] = [
    {
      header: ar.transfers.columns.serial,
      cell: (t) => (
        <Link to={`/transfers/${t.id}`} className="ltr">
          {t.serial_no}
        </Link>
      ),
    },
    { header: ar.transfers.columns.from, cell: (t) => t.from_branch?.name ?? '—' },
    { header: ar.transfers.columns.status, cell: (t) => <TransferStatusBadge status={t.status} /> },
    { header: ar.transfers.columns.count, cell: (t) => <span className="ltr">{t.items_count ?? '—'}</span> },
    { header: ar.transfers.columns.sentAt, cell: (t) => formatDateTime(t.sent_at) },
    { header: ar.transfers.columns.driver, cell: (t) => t.driver_text ?? '—' },
  ];

  return (
    <>
      <PageHeader title={ar.transfers.title}>
        {can('transfer.create') && <Button onClick={() => navigate('/transfers/new')}>{ar.transfers.new}</Button>}
      </PageHeader>

      <div className="toolbar">
        {can('transfer.confirm_receipt') && (
          <input
            className="input ltr"
            aria-label={ar.transfers.scanLabel}
            placeholder={ar.transfers.scanPlaceholder}
            value={scan}
            onChange={(event) => setScan(event.target.value)}
            onKeyDown={onScan}
            autoFocus
          />
        )}
        <select className="input" aria-label={ar.common.status} value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">{ar.transfers.allStatuses}</option>
          {(['sent', 'received', 'received_with_diff'] as const).map((value) => (
            <option key={value} value={value}>
              {ar.transfers.status[value]}
            </option>
          ))}
        </select>
      </div>

      {scanError && <Notice tone="error">{scanError}</Notice>}
      {transfers.isError && <Notice tone="error">{ar.common.loadFailed}</Notice>}

      <DataTable columns={columns} rows={transfers.data?.data ?? []} rowKey={(t) => t.id} loading={transfers.isPending} />
      <Pagination meta={transfers.data?.meta} onPage={setPage} />
    </>
  );
}
