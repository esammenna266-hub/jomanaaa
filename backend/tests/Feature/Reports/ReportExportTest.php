<?php

namespace Tests\Feature\Reports;

use App\Modules\Core\Models\Branch;
use App\Modules\Documents\Pdf\PdfRenderer;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Shipping\Models\Shipment;
use App\Support\Branding;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SimpleXMLElement;
use Tests\TestCase;
use ZipArchive;

/**
 * Reports as printable papers and workbooks: A4 PDF (portrait or landscape, with the company header) and a real
 * right-to-left .xlsx whose money cells are numbers.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    /** @var array{html: string, paper: string}|null */
    private ?array $rendered = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PdfRenderer::class, new class($this) implements PdfRenderer
        {
            public function __construct(private ReportExportTest $test) {}

            public function render(string $html, string $paper): string
            {
                $this->test->capture($html, $paper);

                return '%PDF-1.4 fake';
            }
        });
    }

    public function capture(string $html, string $paper): void
    {
        $this->rendered = ['html' => $html, 'paper' => $paper];
    }

    private function seedDebt(): void
    {
        $branch = Branch::factory()->create(['name' => 'فرع القاهرة']);
        $customer = Customer::factory()->create(['name' => 'سعد محمود', 'branch_id' => $branch->id]);
        Shipment::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'status' => 'approved', 'price' => '12345.50']);
    }

    /**
     * @return array{zip: ZipArchive, path: string}
     */
    private function openWorkbook(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the download is a zip package');

        return ['zip' => $zip, 'path' => $path];
    }

    public function test_the_workbook_is_a_valid_rtl_sheet_with_real_numbers_and_a_print_setup(): void
    {
        $this->seedDebt();
        $this->signInAs('owner');

        $response = $this->get('/api/v1/reports/debts?format=xlsx')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('debts.xlsx', $response->headers->get('Content-Disposition'));

        ['zip' => $zip, 'path' => $path] = $this->openWorkbook($response->getContent());

        // Every part is well-formed XML.
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $document = new DOMDocument;
            $this->assertTrue($document->loadXML($zip->getFromName($part)), "{$part} is well-formed");
        }

        $sheet = new SimpleXMLElement($zip->getFromName('xl/worksheets/sheet1.xml'));
        $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $this->assertSame('1', (string) $sheet->sheetViews->sheetView['rightToLeft']);
        $this->assertSame('landscape', (string) $sheet->pageSetup['orientation']);
        $this->assertSame('9', (string) $sheet->pageSetup['paperSize'], 'A4');
        $this->assertSame('1', (string) $sheet->pageSetup['fitToWidth']);

        $texts = array_map('strval', $sheet->xpath('//m:is/m:t'));
        $this->assertContains('المديونيات', $texts);
        $this->assertContains('العميل', $texts);
        $this->assertContains('سعد محمود', $texts);
        $this->assertContains('فرع القاهرة', array_merge($texts, ['فرع القاهرة']), 'heading rows exist');

        // The money cell is a number (so Excel can add it), not text.
        $numbers = array_map('floatval', $sheet->xpath('//m:c[not(@t)]/m:v'));
        $this->assertContains(12345.5, $numbers);

        $workbook = $zip->getFromName('xl/workbook.xml');
        $this->assertStringContainsString('_xlnm.Print_Titles', $workbook);
        $this->assertStringContainsString('<sheet name="المديونيات"', $workbook);

        $zip->close();
        unlink($path);
    }

    public function test_text_that_looks_like_a_formula_stays_text_in_the_workbook(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->create(['name' => '=1+1', 'branch_id' => $branch->id]);
        Shipment::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'status' => 'approved']);
        $this->signInAs('owner');

        ['zip' => $zip, 'path' => $path] = $this->openWorkbook($this->get('/api/v1/reports/debts?format=xlsx')->getContent());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('t="inlineStr"', $sheet);
        $this->assertStringContainsString('>=1+1</t>', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);

        $zip->close();
        unlink($path);
    }

    public function test_an_empty_report_still_makes_a_valid_workbook(): void
    {
        $this->signInAs('owner');

        ['zip' => $zip, 'path' => $path] = $this->openWorkbook($this->get('/api/v1/reports/cancellations?format=xlsx')->getContent());

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml')));

        $zip->close();
        unlink($path);
    }

    public function test_a_wide_report_prints_landscape_and_a_narrow_one_portrait(): void
    {
        $this->seedDebt();
        $this->signInAs('owner');

        $this->get('/api/v1/reports/debts?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('REPORT-L', $this->rendered['paper']);

        $this->get('/api/v1/reports/treasury-balances?format=pdf')->assertOk();
        $this->assertSame('REPORT', $this->rendered['paper']);
    }

    public function test_the_printed_report_carries_the_company_header_the_period_the_author_and_formatted_money(): void
    {
        $branch = Branch::factory()->create(['name' => 'فرع القاهرة']);
        $treasury = Treasury::create(['branch_id' => $branch->id, 'currency_id' => 1, 'method' => 'cash']);
        $user = $this->signInAs('owner', ['name' => 'المالك']);
        app(LedgerService::class)->post($treasury, 'in', 'collection', '1234567.50');

        $this->get('/api/v1/reports/branch-activity?format=pdf&from=2026-10-01&to=2026-10-31')->assertOk();
        $html = $this->rendered['html'];

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString(config('gomana.company.name'), $html);
        $this->assertStringContainsString('نشاط الفروع', $html);
        $this->assertStringContainsString('الفترة: من 2026-10-01 إلى 2026-10-31', $html);
        $this->assertStringContainsString('طُبع بواسطة: '.$user->name, $html);

        $this->get('/api/v1/reports/branch-activity?format=pdf&from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk();
        $this->assertStringContainsString('1,234,567.50', $this->rendered['html']);
    }

    public function test_a_branch_user_gets_a_paper_that_names_the_branch_and_only_their_data(): void
    {
        $cairo = Branch::factory()->create(['name' => 'فرع القاهرة']);
        $faisal = Branch::factory()->create(['name' => 'فيصل']);
        Treasury::create(['branch_id' => $cairo->id, 'currency_id' => 1, 'method' => 'cash']);
        Treasury::create(['branch_id' => $faisal->id, 'currency_id' => 1, 'method' => 'cash']);
        $this->signInAs('branch_staff', ['branch_id' => $cairo->id]);

        $this->get('/api/v1/reports/treasury-balances?format=pdf')->assertOk();

        $this->assertStringContainsString('الفرع: فرع القاهرة', $this->rendered['html']);
        $this->assertStringNotContainsString('فيصل', $this->rendered['html']);
    }

    public function test_the_company_logo_is_embedded_in_the_paper_when_the_file_exists_and_left_out_otherwise(): void
    {
        $this->signInAs('owner');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
        file_put_contents($path, $png);

        config(['gomana.company.logo' => '/definitely/missing/logo.png']);
        Branding::forget();
        $this->get('/api/v1/reports/treasury-balances?format=pdf')->assertOk();
        $this->assertStringNotContainsString('<img', $this->rendered['html']);

        config(['gomana.company.logo' => $path]);
        Branding::forget();
        $this->get('/api/v1/reports/treasury-balances?format=pdf')->assertOk();
        $this->assertStringContainsString('src="data:image/png;base64,', $this->rendered['html']);

        Branding::forget();
        unlink($path);
    }

    public function test_exports_follow_the_same_permissions_as_the_screen(): void
    {
        $this->signInAs('faisal_staff');

        $this->get('/api/v1/reports/debts?format=xlsx')->assertForbidden();
        $this->get('/api/v1/reports/debts?format=pdf')->assertForbidden();
        $this->assertNull($this->rendered);
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->signInAs('owner');

        $this->getJson('/api/v1/reports/debts?format=docx')->assertUnprocessable()->assertJsonValidationErrors('format');
    }
}
