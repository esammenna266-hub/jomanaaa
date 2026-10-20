import { apiBlob } from '../../shared/api/client';

const queryString = (from: string, to: string, format: string) => {
  const params = new URLSearchParams({ format });
  if (from) params.set('from', from);
  if (to) params.set('to', to);

  return `?${params.toString()}`;
};

/**
 * Gets a report from the server as an A4 PDF (opened in a new tab, ready to print) or an Excel workbook (saved).
 * The tab is opened first, inside the click, so the browser does not treat it as an unwanted pop-up.
 */
export async function downloadReport(slug: string, from: string, to: string, format: 'pdf' | 'xlsx'): Promise<void> {
  const tab = format === 'pdf' ? window.open('', '_blank') : null;

  try {
    const blob = await apiBlob(`/reports/${slug}${queryString(from, to, format)}`);
    const url = URL.createObjectURL(blob);

    if (format === 'pdf') {
      if (tab) {
        tab.location.href = url;
      } else {
        window.open(url, '_blank');
      }
    } else {
      const link = document.createElement('a');
      link.href = url;
      link.download = `${slug}.xlsx`;
      document.body.appendChild(link);
      link.click();
      link.remove();
    }

    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (error) {
    tab?.close();
    throw error;
  }
}
