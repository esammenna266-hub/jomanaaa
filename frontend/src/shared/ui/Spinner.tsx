import { ar } from '../../i18n/ar';

export function Spinner({ fullPage = false }: { fullPage?: boolean }) {
  return (
    <div className={fullPage ? 'spinner spinner--page' : 'spinner'} role="status">
      {ar.common.loading}
    </div>
  );
}
