import { api, apiBlob } from '../../shared/api/client';

export type DocumentType = 'shipment_form' | 'receipt' | 'waybill' | 'manifest' | 'driver_undertaking' | 'bus_ticket' | 'passenger_manifest';

/**
 * Opens a document's PDF in a new tab. The first call generates and archives it; later calls reuse the stored file.
 * The tab is opened first, inside the click, so the browser does not treat it as an unwanted pop-up.
 */
export async function printDocument(type: DocumentType, subjectId: number): Promise<void> {
  const tab = window.open('', '_blank');

  try {
    const { data } = await api<{ data: { id: number } }>('/documents', { method: 'POST', body: { type, subject_id: subjectId } });
    const blob = await apiBlob(`/documents/${data.id}/download`);
    const url = URL.createObjectURL(blob);

    if (tab) {
      tab.location.href = url;
    } else {
      window.open(url, '_blank');
    }
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (error) {
    tab?.close();
    throw error;
  }
}
