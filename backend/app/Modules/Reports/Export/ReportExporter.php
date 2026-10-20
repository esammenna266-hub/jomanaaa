<?php

namespace App\Modules\Reports\Export;

use App\Modules\Documents\Pdf\PdfRenderer;

/**
 * Turns a report table into the files people print and open: an A4 PDF and an Excel workbook.
 * A wide table (more than five columns) is printed landscape so nothing is squeezed.
 *
 * @phpstan-type Table array{title: string, columns: list<array{key: string, label: string, type?: string}>, rows: list<array<string, mixed>>}
 */
final class ReportExporter
{
    private const MAX_PORTRAIT_COLUMNS = 5;

    public function __construct(private PdfRenderer $pdf, private XlsxWriter $xlsx) {}

    /**
     * @param  array{title: string, columns: list<array{key: string, label: string, type?: string}>, rows: list<array<string, mixed>>}  $table
     * @param  list<string>  $notes
     */
    public function pdf(array $table, array $notes): string
    {
        $landscape = $this->landscape($table);

        $html = view('reports.pdf', [
            'company' => config('gomana.company'),
            'title' => $table['title'],
            'columns' => $table['columns'],
            'rows' => $table['rows'],
            'notes' => $notes,
            'landscape' => $landscape,
        ])->render();

        return $this->pdf->render($html, $landscape ? 'REPORT-L' : 'REPORT');
    }

    /**
     * @param  array{title: string, columns: list<array{key: string, label: string, type?: string}>, rows: list<array<string, mixed>>}  $table
     * @param  list<string>  $notes
     */
    public function xlsx(array $table, array $notes): string
    {
        return $this->xlsx->build(config('gomana.company.name'), $table['title'], $table['columns'], $table['rows'], $notes, $this->landscape($table));
    }

    /**
     * @param  array{title: string, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}  $table
     */
    private function landscape(array $table): bool
    {
        return count($table['columns']) > self::MAX_PORTRAIT_COLUMNS;
    }
}
