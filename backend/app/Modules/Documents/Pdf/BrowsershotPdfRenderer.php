<?php

namespace App\Modules\Documents\Pdf;

use Spatie\Browsershot\Browsershot;

final class BrowsershotPdfRenderer implements PdfRenderer
{
    public function render(string $html, string $paper): string
    {
        $config = config('gomana.pdf');

        $shot = Browsershot::html($html)
            ->setNodeModulePath(base_path('node_modules'))
            ->showBackground()
            ->emulateMedia('print')
            ->timeout($config['timeout']);

        // A bus ticket is a narrow strip (9 x 21 cm); reports are A4 with page numbers; everything else is a standard size.
        if ($paper === 'TICKET') {
            $shot->paperSize(90, 210, 'mm')->margins(4, 4, 4, 4);
        } elseif ($paper === 'REPORT' || $paper === 'REPORT-L') {
            $shot->format('A4')->landscape($paper === 'REPORT-L')->margins(12, 10, 20, 10)->showBrowserHeaderAndFooter()->hideHeader()->footerHtml($this->reportFooter());
        } else {
            $shot->format($paper)->margins(10, 10, 10, 10);
        }

        if ($config['node_binary']) {
            $shot->setNodeBinary($config['node_binary']);
        }

        if ($config['npm_binary']) {
            $shot->setNpmBinary($config['npm_binary']);
        }

        if ($config['chrome_path']) {
            $shot->setChromePath($config['chrome_path']);
        }

        if ($config['no_sandbox']) {
            $shot->noSandbox();
        }

        return $shot->pdf();
    }

    /**
     * The strip printed at the bottom of every report page: the company line and "page x of y".
     */
    private function reportFooter(): string
    {
        $company = config('gomana.company');
        $line = collect([
            $company['address'] ?? null,
            ($company['phones'] ?? null) ? 'تليفون: '.$company['phones'] : null,
            'س.ت: '.$company['commercial_register'].' — ب.ض: '.$company['tax_card'],
        ])->filter()->implode(' — ');

        return '<div style="width:100%;font-size:9px;color:#444;text-align:center;direction:rtl;font-family:Tahoma,Arial,sans-serif;padding:0 10mm">'
            .e($line)
            .'<div style="margin-top:2px">صفحة <span class="pageNumber"></span> من <span class="totalPages"></span></div></div>';
    }
}
