import { ar } from '../../i18n/ar';
import type { ShipmentStatus } from '../../shared/api/types';

const TONE: Record<ShipmentStatus, 'muted' | 'warn' | 'info' | 'ok' | 'danger'> = {
  draft: 'muted',
  pending_approval: 'warn',
  approved: 'info',
  at_branch: 'info',
  to_main_warehouse: 'info',
  at_main_warehouse: 'info',
  loaded: 'info',
  in_transit: 'info',
  arrived: 'ok',
  delivered: 'ok',
  cancelled: 'danger',
  lost: 'danger',
  damaged: 'danger',
};

export function ShipmentStatusBadge({ status }: { status: ShipmentStatus }) {
  return <span className={`badge badge--${TONE[status]}`}>{ar.shipments.status[status]}</span>;
}
