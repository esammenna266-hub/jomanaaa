import { ar } from '../../i18n/ar';
import type { PageMeta } from '../api/types';
import { Button } from './Button';

interface PaginationProps {
  meta: PageMeta | undefined;
  onPage: (page: number) => void;
}

export function Pagination({ meta, onPage }: PaginationProps) {
  if (!meta || meta.last_page <= 1) {
    return null;
  }

  return (
    <nav className="pagination" aria-label="pagination">
      <Button variant="secondary" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
        {ar.common.previous}
      </Button>
      <span>{ar.common.pageOf(meta.current_page, meta.last_page, meta.total)}</span>
      <Button variant="secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
        {ar.common.next}
      </Button>
    </nav>
  );
}
