import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { ShipmentFile } from '../../shared/api/types';
import { formatDate, formatDateTime, formatMoney } from '../../shared/format';
import { Button } from '../../shared/ui/Button';
import { DataTable, type Column } from '../../shared/ui/DataTable';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Spinner } from '../../shared/ui/Spinner';
import { printDocument, type DocumentType } from '../documents/api';
import { currencyName, useCurrencies } from '../master-data/api';
import { cancelShipment, decideApproval, deliverShipment, receiveShipment, submitShipment, useShipment } from './api';
import { CANCELLABLE, COLLECTABLE } from './rules';
import { ReasonDialog } from '../../shared/ui/ReasonDialog';
import { CollectDialog, InstallmentsDialog } from './ShipmentDialogs';
import { ShipmentStatusBadge } from './ShipmentStatusBadge';

type Dialog = { kind: 'collect' | 'installments' | 'cancel' | 'deliver' } | { kind: 'reject'; approvalId: number } | null;

export function ShipmentFilePage() {
  const id = Number(useParams().id);
  const location = useLocation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = useAuth();
  const currencies = useCurrencies();
  const query = useShipment(id);
  const [dialog, setDialog] = useState<Dialog>(null);

  const refresh = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['shipment', id] }),
      queryClient.invalidateQueries({ queryKey: ['shipments'] }),
    ]);
  };
  const run = useMutation({ mutationFn: (call: () => Promise<unknown>) => call(), onSuccess: refresh });
  const print = useMutation({ mutationFn: ({ type, subjectId }: { type: DocumentType; subjectId: number }) => printDocument(type, subjectId) });

  if (query.isPending) {
    return <Spinner />;
  }

  if (query.isError) {
    return <Notice tone="error">{query.error.message}</Notice>;
  }

  const shipment = query.data;
  const notice = (location.state as { notice?: string | null } | null)?.notice;
  const money = (amount: string, currencyId: number): ReactNode => (
    <>
      <span className="ltr">{formatMoney(amount)}</span> {currencyName(currencies.data, currencyId)}
    </>
  );

  const remaining = Number(shipment.balance.remaining);
  const canCollect = can('payment.collect') && COLLECTABLE.includes(shipment.status) && remaining > 0;
  const reversed = new Set(shipment.payments.map((payment) => payment.reversal_of_id).filter((value): value is number => value !== null));
  const error = run.error ?? print.error;

  const paymentColumns: Column<ShipmentFile['payments'][number]>[] = [
    { header: ar.shipments.receiptNo, cell: (p) => <span className="ltr">{p.serial_no}</span> },
    { header: ar.shipments.date, cell: (p) => formatDateTime(p.created_at) },
    {
      header: ar.shipments.kindLabel,
      cell: (p) => (
        <>
          {ar.shipments.paymentKind[p.kind]} {reversed.has(p.id) && <span className="badge badge--off">{ar.shipments.reversed}</span>}
        </>
      ),
    },
    { header: ar.shipments.amount, cell: (p) => <>{p.direction === 'out' ? '−' : ''}{money(p.amount, p.currency_id)}</> },
    {
      header: ar.common.actions,
      cell: (p) =>
        p.kind === 'collection' && !reversed.has(p.id) ? (
          <Button variant="ghost" disabled={print.isPending} onClick={() => print.mutate({ type: 'receipt', subjectId: p.id })}>
            {ar.shipments.printReceipt}
          </Button>
        ) : null,
    },
  ];

  return (
    <>
      <PageHeader title={ar.shipments.file(shipment.serial_no)}>
        <div className="actions-bar">
          <Button variant="secondary" loading={print.isPending} onClick={() => print.mutate({ type: 'shipment_form', subjectId: shipment.id })}>
            {print.isPending ? ar.shipments.printing : ar.shipments.printForm}
          </Button>

          {shipment.status === 'draft' && can('shipment.create') && (
            <>
              <Button variant="secondary" onClick={() => navigate(`/shipments/${shipment.id}/edit`)}>
                {ar.common.edit}
              </Button>
              <Button loading={run.isPending} onClick={() => run.mutate(() => submitShipment(shipment.id))}>
                {ar.shipments.submit}
              </Button>
            </>
          )}
          {shipment.status === 'approved' && can('shipment.receive') && (
            <Button loading={run.isPending} onClick={() => run.mutate(() => receiveShipment(shipment.id))}>
              {ar.shipments.receive}
            </Button>
          )}
          {canCollect && <Button onClick={() => setDialog({ kind: 'collect' })}>{ar.shipments.collect}</Button>}
          {canCollect && (
            <Button variant="secondary" onClick={() => setDialog({ kind: 'installments' })}>
              {ar.shipments.scheduleInstallments}
            </Button>
          )}
          {shipment.status === 'arrived' && can('shipment.deliver') && <Button onClick={() => setDialog({ kind: 'deliver' })}>{ar.shipments.deliver}</Button>}
          {CANCELLABLE.includes(shipment.status) && can('shipment.cancel') && (
            <Button variant="danger" onClick={() => setDialog({ kind: 'cancel' })}>
              {ar.shipments.cancel}
            </Button>
          )}
        </div>
      </PageHeader>

      {notice && <Notice tone="error">{notice}</Notice>}
      {error && <Notice tone="error">{error.message}</Notice>}
      {shipment.status === 'cancelled' && shipment.cancel_reason && (
        <Notice tone="error">
          {ar.shipments.cancelReason}: {shipment.cancel_reason}
        </Notice>
      )}

      <div className="file-head">
        <ShipmentStatusBadge status={shipment.status} />
        <span>{ar.shipments.type[shipment.type]}</span>
        <span className="muted">
          {ar.shipments.tracking}: <span className="ltr">{shipment.tracking_code}</span>
        </span>
      </div>

      <div className="grid-2">
        <section className="card">
          <dl className="dl">
            <dt>{ar.shipments.customer}</dt>
            <dd>
              {shipment.customer?.name} <span className="ltr muted">{shipment.customer?.phone}</span>
            </dd>
            <dt>{ar.shipments.consigneeLabel}</dt>
            <dd>
              {shipment.consignee_name} <span className="ltr muted">{shipment.consignee_phone}</span>
            </dd>
            <dt>{ar.shipments.branch}</dt>
            <dd>{shipment.branch?.name ?? '—'}</dd>
            <dt>{ar.shipments.destinationLabel}</dt>
            <dd>{shipment.destination?.name ?? '—'}</dd>
            {shipment.bus_trip && (
              <>
                <dt>{ar.shipments.busTrip}</dt>
                <dd>
                  {can('trip.view') ? <Link to={`/passenger-trips/${shipment.bus_trip.id}`}>{shipment.bus_trip.route}</Link> : shipment.bus_trip.route} (
                  {formatDateTime(shipment.bus_trip.depart_at)})
                </dd>
              </>
            )}
            <dt>{ar.shipments.borderPoint}</dt>
            <dd>{shipment.border_point?.name ?? '—'}</dd>
            <dt>{ar.shipments.clearingAgent}</dt>
            <dd>{shipment.clearing_agent?.name ?? '—'}</dd>
            <dt>{ar.shipments.expectedDate}</dt>
            <dd>{formatDate(shipment.expected_date)}</dd>
            {shipment.proposed_category && (
              <>
                <dt>{ar.shipments.proposedVehicle}</dt>
                <dd>
                  {ar.shipments.vehicleCategory[shipment.proposed_category]}
                  {shipment.proposed_body && ` ${ar.shipments.vehicleBody[shipment.proposed_body]}`}
                  {shipment.proposed_tons && ` — ${Number(shipment.proposed_tons)} ${ar.shipments.proposedTons}`}
                </dd>
              </>
            )}
            {shipment.delivered_to && (
              <>
                <dt>{ar.shipments.deliveredTo}</dt>
                <dd>
                  {shipment.delivered_to} ({formatDateTime(shipment.delivered_at)})
                </dd>
              </>
            )}
            {shipment.notes && (
              <>
                <dt>{ar.shipments.notes}</dt>
                <dd>{shipment.notes}</dd>
              </>
            )}
          </dl>
        </section>

        <section className="card">
          <h2>{ar.shipments.balanceTitle}</h2>
          <dl className="dl">
            <dt>{ar.shipments.price}</dt>
            <dd>{money(shipment.price, shipment.currency_id)}</dd>
            {Number(shipment.discount) > 0 && (
              <>
                <dt>{ar.shipments.discount}</dt>
                <dd>{money(shipment.discount, shipment.currency_id)}</dd>
              </>
            )}
            {shipment.agent_sale_price && (
              <>
                <dt>{ar.shipments.agentSalePrice}</dt>
                <dd>{money(shipment.agent_sale_price, shipment.currency_id)}</dd>
              </>
            )}
            <dt>{ar.shipments.totalDue}</dt>
            <dd>
              <strong>{money(shipment.balance.total_due, shipment.currency_id)}</strong>
            </dd>
            <dt>{ar.shipments.paid}</dt>
            <dd>{money(shipment.balance.paid, shipment.currency_id)}</dd>
            <dt>{ar.shipments.remaining}</dt>
            <dd>
              <strong className={remaining > 0 ? 'text-danger' : 'text-ok'}>{money(shipment.balance.remaining, shipment.currency_id)}</strong>
            </dd>
          </dl>
          {shipment.balance.other_currencies.length > 0 && (
            <Notice>
              {ar.shipments.otherCurrencies}:{' '}
              {shipment.balance.other_currencies.map((other) => (
                <span key={other.currency_id}>{money(other.net, other.currency_id)} </span>
              ))}
            </Notice>
          )}
        </section>
      </div>

      <h2 className="section-title">{ar.shipments.itemsTitle}</h2>
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th scope="col">{ar.shipments.itemName}</th>
              <th scope="col">{ar.shipments.itemQty}</th>
              <th scope="col">{ar.shipments.itemUnit}</th>
              <th scope="col">{ar.shipments.itemWeight}</th>
            </tr>
          </thead>
          <tbody>
            {shipment.items.map((item) => (
              <tr key={item.id}>
                <td>{item.name}</td>
                <td className="ltr">{Number(item.qty)}</td>
                <td>{ar.shipments.units[item.unit]}</td>
                <td className="ltr">{item.weight_kg ? Number(item.weight_kg) : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {shipment.approvals.length > 0 && (
        <>
          <h2 className="section-title">{ar.shipments.approvalsTitle}</h2>
          <ul className="approvals">
            {shipment.approvals.map((approval) => (
              <li key={approval.id} className="card approvals__item">
                <div>
                  <strong>{ar.shipments.approvalType[approval.type]}</strong>
                  {approval.value && <> — <span className="ltr">{formatMoney(approval.value)}</span></>}
                  <span className={`badge badge--${approval.status === 'approved' ? 'ok' : approval.status === 'rejected' ? 'danger' : 'warn'}`}>
                    {ar.shipments.approvalStatus[approval.status]}
                  </span>
                  {approval.reason && <div className="muted">{approval.reason}</div>}
                </div>
                {approval.status === 'pending' && can('shipment.approve_price') && (
                  <div className="row-actions">
                    <Button loading={run.isPending} onClick={() => run.mutate(() => decideApproval(approval.id, 'approve'))}>
                      {ar.shipments.approve}
                    </Button>
                    <Button variant="secondary" onClick={() => setDialog({ kind: 'reject', approvalId: approval.id })}>
                      {ar.shipments.reject}
                    </Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        </>
      )}

      {shipment.installments.length > 0 && (
        <>
          <h2 className="section-title">{ar.shipments.installmentsTitle}</h2>
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th scope="col">{ar.shipments.dueDate}</th>
                  <th scope="col">{ar.shipments.amount}</th>
                  <th scope="col">{ar.common.status}</th>
                </tr>
              </thead>
              <tbody>
                {shipment.installments.map((installment) => (
                  <tr key={installment.id}>
                    <td>{formatDate(installment.due_date)}</td>
                    <td>{money(installment.amount, installment.currency_id)}</td>
                    <td>
                      <span className={`badge badge--${installment.status === 'paid' ? 'ok' : installment.status === 'overdue' ? 'danger' : 'muted'}`}>
                        {ar.shipments.installmentStatus[installment.status]}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      {shipment.payments.length > 0 && (
        <>
          <h2 className="section-title">{ar.shipments.paymentsTitle}</h2>
          <DataTable columns={paymentColumns} rows={shipment.payments} rowKey={(p) => p.id} />
        </>
      )}

      <p>
        <Link to="/shipments">← {ar.shipments.title}</Link>
      </p>

      {dialog?.kind === 'collect' && <CollectDialog shipment={shipment} onDone={refresh} onClose={() => setDialog(null)} />}
      {dialog?.kind === 'installments' && <InstallmentsDialog shipment={shipment} onDone={refresh} onClose={() => setDialog(null)} />}
      {dialog?.kind === 'cancel' && (
        <ReasonDialog
          title={ar.shipments.cancelTitle}
          label={ar.shipments.cancelReason}
          confirmLabel={ar.shipments.cancel}
          intro={ar.shipments.cancelWarning}
          danger
          onConfirm={async (reason) => {
            await cancelShipment(shipment.id, reason);
            await refresh();
          }}
          onClose={() => setDialog(null)}
        />
      )}
      {dialog?.kind === 'deliver' && (
        <ReasonDialog
          title={ar.shipments.deliver}
          label={ar.shipments.receiverName}
          confirmLabel={ar.shipments.deliver}
          intro={ar.shipments.deliverHint}
          onConfirm={async (name) => {
            await deliverShipment(shipment.id, name);
            await refresh();
          }}
          onClose={() => setDialog(null)}
        />
      )}
      {dialog?.kind === 'reject' && (
        <ReasonDialog
          title={ar.shipments.rejectTitle}
          label={ar.shipments.reason}
          confirmLabel={ar.shipments.reject}
          danger
          onConfirm={async (reason) => {
            await decideApproval(dialog.approvalId, 'reject', reason);
            await refresh();
          }}
          onClose={() => setDialog(null)}
        />
      )}
    </>
  );
}
