<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Pdf\BrowsershotPdfRenderer;
use Tests\TestCase;

/**
 * Runs the real headless Chromium. Skipped unless GOMANA_PDF_E2E=1 (needs Node, puppeteer and a Chromium/Edge binary).
 */
class RealPdfRenderingTest extends TestCase
{
    public function test_chromium_renders_arabic_html_to_a_pdf(): void
    {
        if (! env('GOMANA_PDF_E2E')) {
            $this->markTestSkipped('Set GOMANA_PDF_E2E=1 (and PDF_CHROME_PATH) to run the real PDF engine.');
        }

        $html = '<html dir="rtl" lang="ar"><body style="font-family:Tahoma,sans-serif"><h1>استمارة شحن 000123</h1></body></html>';

        $pdf = (new BrowsershotPdfRenderer)->render($html, 'A5');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
    }
}
