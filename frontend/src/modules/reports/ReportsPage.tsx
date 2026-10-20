import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { ar } from '../../i18n/ar';
import { Button } from '../../shared/ui/Button';
import { TextField } from '../../shared/ui/Fields';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Spinner } from '../../shared/ui/Spinner';
import { REPORTS, useReport } from './api';
import { downloadReport } from './download';

/** The management and finance reports: pick one, set the period if it has one, read it, print it or open it in Excel. */
export function ReportsPage() {
  const [slug, setSlug] = useState(REPORTS[0]!.slug);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const definition = REPORTS.find((report) => report.slug === slug)!;
  const range = definition.dated ? { from, to } : { from: '', to: '' };
  const report = useReport(slug, range.from, range.to);
  const download = useMutation({ mutationFn: (format: 'pdf' | 'xlsx') => downloadReport(slug, range.from, range.to, format) });

  return (
    <>
      <PageHeader title={ar.reports.title}>
        <div className="actions-bar no-print">
          <Button variant="secondary" disabled={!report.data || download.isPending} onClick={() => download.mutate('pdf')}>
            {ar.reports.pdf}
          </Button>
          <Button variant="secondary" disabled={!report.data || download.isPending} onClick={() => download.mutate('xlsx')}>
            {ar.reports.excel}
          </Button>
        </div>
      </PageHeader>

      <div className="toolbar no-print">
        <select className="input" aria-label={ar.reports.report} value={slug} onChange={(event) => setSlug(event.target.value)}>
          {REPORTS.map((item) => (
            <option key={item.slug} value={item.slug}>
              {ar.reports.names[item.slug as keyof typeof ar.reports.names]}
            </option>
          ))}
        </select>
        {definition.dated && (
          <>
            <TextField label={ar.reports.from} type="date" value={from} onChange={(event) => setFrom(event.target.value)} ltr />
            <TextField label={ar.reports.to} type="date" value={to} onChange={(event) => setTo(event.target.value)} ltr />
          </>
        )}
      </div>

      {download.isError && <Notice tone="error">{download.error.message}</Notice>}
      {report.isError && <Notice tone="error">{report.error.message}</Notice>}
      {report.isPending && <Spinner />}

      {report.data && (
        <section className="report">
          <h2 className="report__title">
            {report.data.title}
            {definition.dated && (from || to) && (
              <span className="muted">
                {' '}
                — {from || '…'} → {to || '…'}
              </span>
            )}
          </h2>
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  {report.data.columns.map((column) => (
                    <th key={column.key} scope="col">
                      {column.label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {report.data.rows.length === 0 && (
                  <tr>
                    <td colSpan={report.data.columns.length} className="table__empty">
                      {ar.common.empty}
                    </td>
                  </tr>
                )}
                {report.data.rows.map((row, index) => (
                  <tr key={index}>
                    {report.data.columns.map((column) => (
                      <td key={column.key}>{row[column.key]}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </>
  );
}
