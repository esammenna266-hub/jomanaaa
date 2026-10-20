import { ar } from '../../i18n/ar';

export function StatusBadge({ active }: { active: boolean }) {
  return <span className={`badge ${active ? 'badge--ok' : 'badge--off'}`}>{active ? ar.common.active : ar.common.inactive}</span>;
}
