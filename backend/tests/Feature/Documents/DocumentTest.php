<?php

namespace Tests\Feature\Documents;

use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Documents\Pdf\PdfRenderer;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Driver;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShipmentItem;
use App\Modules\Trips\Models\CargoTrip;
use App\Modules\Trips\Models\DriverUndertaking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    /** @var object{calls: list<array{0: string, 1: string}>, crash: bool} */
    private object $renderer;

    private Branch $branch;

    private User $staff;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->renderer = new class implements PdfRenderer
        {
            /** @var list<array{0: string, 1: string}> */
            public array $calls = [];

            public bool $crash = false;

            public function render(string $html, string $paper): string
            {
                if ($this->crash) {
                    throw new RuntimeException('chromium crashed');
                }

                $this->calls[] = [$html, $paper];

                return '%PDF-fake '.md5($html);
            }
        };
        $this->app->instance(PdfRenderer::class, $this->renderer);

        $this->branch = Branch::factory()->create();
        $this->staff = User::factory()->withRole('branch_staff')->create(['branch_id' => $this->branch->id]);
        $this->shipment = $this->shipmentWithItems(['branch_id' => $this->branch->id, 'customer_id' => Customer::factory()->create(['name' => 'سعد محمود'])->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shipmentWithItems(array $attributes = []): Shipment
    {
        $shipment = Shipment::factory()->create(['consignee_name' => 'عمر الطيب', ...$attributes]);
        ShipmentItem::create(['shipment_id' => $shipment->id, 'name' => 'ملابس', 'qty' => 10, 'unit' => 'carton', 'weight_kg' => 100]);

        return $shipment;
    }

    private function issue(string $type, int $subjectId)
    {
        return $this->postJson('/api/v1/documents', ['type' => $type, 'subject_id' => $subjectId]);
    }

    private function lastHtml(): string
    {
        return end($this->renderer->calls)[0];
    }

    /**
     * @return array{trip: CargoTrip, undertaking: DriverUndertaking}
     */
    private function tripWith(Shipment ...$shipments): array
    {
        $trip = CargoTrip::create([
            'serial_no' => '000007',
            'vehicle_id' => Vehicle::create(['plate_no' => 'TRK 1', 'category' => 'jumbo'])->id,
            'driver_id' => Driver::create(['name' => 'حسن', 'phone' => '+201012345678'])->id,
            'stage' => 'loaded',
        ]);
        Storage::put('fingerprints/test.png', 'png-bytes');
        $undertaking = DriverUndertaking::create([
            'serial_no' => '000009', 'cargo_trip_id' => $trip->id, 'driver_id' => $trip->driver_id,
            'delivery_days' => 5, 'daily_penalty' => '100', 'currency_id' => 2, 'fingerprint_path' => 'fingerprints/test.png', 'signed_at' => now(),
        ]);
        foreach ($shipments as $shipment) {
            $shipment->update(['cargo_trip_id' => $trip->id, 'status' => 'loaded']);
        }

        return ['trip' => $trip, 'undertaking' => $undertaking];
    }

    public function test_shipment_form_is_generated_once_stored_and_numbered_like_the_shipment(): void
    {
        $this->actingAs($this->staff, 'sanctum');

        $first = $this->issue('shipment_form', $this->shipment->id)->assertCreated()
            ->assertJsonPath('data.serial_no', $this->shipment->serial_no)
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.pdf_path');
        $second = $this->issue('shipment_form', $this->shipment->id)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertCount(1, $this->renderer->calls);
        $this->assertSame('A4', $this->renderer->calls[0][1]);
        foreach (['استمارة شحن', 'سعد محمود', 'عمر الطيب', 'ملابس', '39187', '769/443/052', 'dir="rtl"'] as $expected) {
            $this->assertStringContainsString($expected, $this->lastHtml());
        }
        Storage::disk('local')->assertExists("documents/shipment_form/{$this->shipment->serial_no}-v1.pdf");
    }

    public function test_documents_for_external_offices_never_show_the_company_price(): void
    {
        $office = Agent::factory()->create();
        $shipment = $this->shipmentWithItems([
            'agent_id' => $office->id, 'price' => '1000.00', 'agent_sale_price' => '1500.00',
            'customer_id' => Customer::factory()->create(['agent_id' => $office->id])->id,
        ]);
        $this->signInAs('agent', ['agent_id' => $office->id]);

        $this->issue('shipment_form', $shipment->id)->assertCreated();

        $this->assertStringContainsString('1,500.00', $this->lastHtml());
        $this->assertStringNotContainsString('1,000.00', $this->lastHtml());
    }

    public function test_receipt_spells_the_amount_and_uses_its_own_sequence(): void
    {
        $this->shipment->update(['status' => 'approved']);
        $treasury = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'cash']);
        $payment = app(LedgerService::class)->post($treasury, 'in', 'collection', '400.00', [
            'shipment_id' => $this->shipment->id, 'payer_type' => 'customer', 'payer_id' => $this->shipment->customer_id,
        ]);
        $this->actingAs($this->staff, 'sanctum');

        $this->issue('receipt', $payment->id)->assertCreated()->assertJsonPath('data.serial_no', '000001');

        $this->assertSame('A5', $this->renderer->calls[0][1]);
        foreach (['أربعمائة جنيه مصري لا غير', 'نقدي', '600.00'] as $expected) {
            $this->assertStringContainsString($expected, $this->lastHtml());
        }

        $manual = app(LedgerService::class)->post($treasury, 'in', 'manual', '5.00');
        $this->issue('receipt', $manual->id)->assertUnprocessable();

        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);
        $this->issue('receipt', $payment->id)->assertNotFound();
    }

    public function test_trip_documents_waybill_manifest_and_undertaking(): void
    {
        $other = $this->shipmentWithItems();
        ['trip' => $trip, 'undertaking' => $undertaking] = $this->tripWith($this->shipment, $other);

        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $this->issue('waybill', $this->shipment->id)->assertForbidden();

        $this->signInAs('faisal_staff');
        $this->issue('waybill', $other->id)->assertCreated()->assertJsonPath('data.serial_no', '000001');
        $html = $this->lastHtml();
        $this->assertStringContainsString('data:image', $html);
        $this->assertMatchesRegularExpression('/ورقة\s*<span class="num">2<\/span>\s*من\s*<span class="num">2<\/span>/u', $html);
        $this->assertStringNotContainsString('السعر', $html);

        $this->issue('manifest', $trip->id)->assertCreated()->assertJsonPath('data.serial_no', '000007');
        $this->assertStringContainsString('مانيفستو البضاعة', $this->lastHtml());
        $this->assertStringContainsString('عدد الشحنات', $this->lastHtml());

        $this->issue('driver_undertaking', $undertaking->id)->assertCreated()->assertJsonPath('data.serial_no', '000009');
        $this->assertStringContainsString('أتعهد أنا الموقع', $this->lastHtml());
        $this->assertStringContainsString('data:image', $this->lastHtml());

        $loose = Shipment::factory()->create();
        $this->issue('waybill', $loose->id)->assertUnprocessable();
    }

    public function test_first_download_locks_the_document_and_reprints_reuse_the_file(): void
    {
        $this->actingAs($this->staff, 'sanctum');
        $id = $this->issue('shipment_form', $this->shipment->id)->json('data.id');

        $response = $this->get("/api/v1/documents/{$id}/download")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-fake', $response->streamedContent());
        $this->assertDatabaseHas('documents', ['id' => $id, 'status' => 'locked']);
        $this->assertNotNull(DB::table('documents')->where('id', $id)->value('locked_at'));

        $this->get("/api/v1/documents/{$id}/download")->assertOk();
        $this->assertCount(1, $this->renderer->calls);

        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);
        $this->get("/api/v1/documents/{$id}/download")->assertNotFound();
    }

    public function test_edit_request_flow_creates_a_new_version_and_keeps_the_old_file(): void
    {
        $this->actingAs($this->staff, 'sanctum');
        $id = $this->issue('shipment_form', $this->shipment->id)->json('data.id');

        $this->postJson("/api/v1/documents/{$id}/edit-requests", ['reason' => 'wrong name'])
            ->assertUnprocessable()->assertJsonValidationErrors('document');

        $this->get("/api/v1/documents/{$id}/download")->assertOk();
        $requestId = $this->postJson("/api/v1/documents/{$id}/edit-requests", ['reason' => 'wrong name'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('documents', ['id' => $id, 'status' => 'edit_requested']);
        $this->postJson("/api/v1/documents/{$id}/edit-requests", ['reason' => 'again'])->assertUnprocessable();

        $this->postJson("/api/v1/edit-requests/{$requestId}/decide", ['decision' => 'approve'])->assertForbidden();

        $this->shipment->update(['consignee_name' => 'اسم مصحح']);
        $this->signInAs('owner');
        $this->getJson('/api/v1/edit-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document.serial_no', $this->shipment->serial_no);
        $this->postJson("/api/v1/edit-requests/{$requestId}/decide", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('documents', ['id' => $id, 'status' => 'superseded']);
        $this->assertDatabaseHas('documents', ['serial_no' => $this->shipment->serial_no, 'version' => 2, 'status' => 'issued']);
        $this->assertStringContainsString('نسخة معدلة رقم 2', $this->lastHtml());
        $this->assertStringContainsString('اسم مصحح', $this->lastHtml());
        Storage::disk('local')->assertExists("documents/shipment_form/{$this->shipment->serial_no}-v1.pdf");
        Storage::disk('local')->assertExists("documents/shipment_form/{$this->shipment->serial_no}-v2.pdf");

        $this->issue('shipment_form', $this->shipment->id)->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_rejected_edit_request_relocks_the_document(): void
    {
        $this->actingAs($this->staff, 'sanctum');
        $id = $this->issue('shipment_form', $this->shipment->id)->json('data.id');
        $this->get("/api/v1/documents/{$id}/download")->assertOk();
        $requestId = $this->postJson("/api/v1/documents/{$id}/edit-requests", ['reason' => 'typo'])->json('data.id');

        $this->signInAs('owner');
        $this->postJson("/api/v1/edit-requests/{$requestId}/decide", ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/edit-requests/{$requestId}/decide", ['decision' => 'reject', 'reason' => 'not needed'])->assertOk();

        $this->assertDatabaseHas('documents', ['id' => $id, 'status' => 'locked']);
        $this->postJson("/api/v1/edit-requests/{$requestId}/decide", ['decision' => 'approve'])->assertUnprocessable();
        $this->assertCount(1, $this->renderer->calls);
    }

    public function test_archive_search_is_limited_to_the_users_branch(): void
    {
        $this->actingAs($this->staff, 'sanctum');
        $this->issue('shipment_form', $this->shipment->id)->assertCreated();

        $this->getJson('/api/v1/documents?type=shipment_form')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/documents?serial_no='.$this->shipment->serial_no)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/documents?type=receipt')->assertOk()->assertJsonCount(0, 'data');

        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonCount(0, 'data');

        $this->signInAs('owner');
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unknown_types_are_rejected_and_render_failures_burn_no_serial(): void
    {
        $this->actingAs($this->staff, 'sanctum');
        $this->issue('invoice', 1)->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->renderer->crash = true;
        $treasury = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'cash']);
        $this->shipment->update(['status' => 'approved']);
        $payment = app(LedgerService::class)->post($treasury, 'in', 'collection', '10', ['shipment_id' => $this->shipment->id]);

        $this->issue('receipt', $payment->id)->assertServerError();

        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(1, (int) DB::table('document_sequences')->where('doc_type', 'receipt')->value('next_no'));
    }
}
