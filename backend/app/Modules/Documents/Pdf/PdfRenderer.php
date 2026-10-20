<?php

namespace App\Modules\Documents\Pdf;

interface PdfRenderer
{
    /**
     * Turns a full HTML document into PDF bytes.
     *
     * @param  string  $paper  'A4', 'A5', 'TICKET' (a 9 x 21 cm strip), or 'REPORT' / 'REPORT-L' (A4 portrait / landscape with page numbers)
     */
    public function render(string $html, string $paper): string;
}
