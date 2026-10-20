<?php

namespace Tests\Feature\Trips;

use App\Modules\Core\Models\Branch;
use App\Modules\MasterData\Models\Driver;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CargoTripTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Vehicle $truck;

    private Driver $driver;

    private Shipment $first;

    private Shipment $second;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->truck = Vehicle::create(['plate_no' => 'TRK 1', 'category' => 'jumbo', 'body' => 'closed', 'max_ton' => 20]);
        $this->driver = Driver::create(['name' => 'Hassan', 'phone' => '+201012345678', 'id_expiry_date' => now()->addYear()->toDateString()]);
        $this->first = $this->warehouseShipment('Clothes', 10, 100);
        $this->second = $this->warehouseShipment('Shoes', 4, 50);
        $this->signInAs('faisal_staff');
    }

    private function warehouseShipment(string $item, int $qty, int $weight): Shipment
    {
        $shipment = Shipment::factory()->create(['status' => 'at_main_warehouse']);
        ShipmentItem::create(['shipment_id' => $shipment->id, 'name' => $item, 'qty' => $qty, 'unit' => 'carton', 'weight_kg' => $weight]);

        return $shipment;
    }

    private function load(array $overrides = [])
    {
        return $this->post('/api/v1/cargo-trips', [
            'vehicle_id' => $this->truck->id,
            'driver_id' => $this->driver->id,
            'shipment_ids' => [$this->first->id, $this->second->id],
            'fingerprint' => UploadedFile::fake()->create('print.png', 20, 'image/png'),
            'pickup_point' => 'Faisal',
            'delivery_point' => 'Omdurman',
            'delivery_days' => 5,
            'daily_penalty' => '100',
            'currency_id' => 2,
            ...$overrides,
        ], ['Accept' => 'application/json']);
    }

    public function test_loading_creates_the_trip_undertaking_and_stores_the_fingerprint(): void
    {
        $response = $this->load()->assertCreated()
            ->assertJsonPath('data.serial_no', '000001')
            ->assertJsonPath('data.stage', 'loaded')
            ->assertJsonPath('data.undertaking.serial_no', '000001')
            ->assertJsonPath('data.events.0.stage', 'loaded')
            ->assertJsonMissingPath('data.undertaking.fingerprint_path');

        $this->assertCount(2, $response->json('data.shipments'));
        $this->assertSame(2, DB::table('shipments')->where(['status' => 'loaded', 'cargo_trip_id' => $response->json('data.id')])->count());
        Storage::disk('local')->assertExists(DB::table('driver_undertakings')->value('fingerprint_path'));
    }

    public function test_fingerprint_is_only_reachable_through_a_valid_signed_url(): void
    {
        $url = $this->load()->json('data.fingerprint_url');
        $this->assertNotNull($url);

        $this->get($url)->assertOk();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $url))->assertForbidden();
        $this->get('/api/v1/cargo-trips/1/fingerprint')->assertForbidden();
    }

    public function test_fingerprint_is_mandatory_and_must_be_an_image(): void
    {
        $this->load(['fingerprint' => null])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->load(['fingerprint' => UploadedFile::fake()->create('print.pdf', 20, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->assertSame(0, DB::table('cargo_trips')->count());
    }

    public function test_only_ready_normal_shipments_can_be_loaded_and_nothing_is_saved_on_failure(): void
    {
        $draft = Shipment::factory()->create();
        $express = Shipment::factory()->create(['status' => 'at_main_warehouse', 'type' => 'express']);

        $this->load(['shipment_ids' => [$this->first->id, $draft->id]])->assertUnprocessable()->assertJsonValidationErrors('shipment_ids');
        $this->load(['shipment_ids' => [$express->id]])->assertUnprocessable();

        $this->assertSame(0, DB::table('cargo_trips')->count());
        $this->assertSame('at_main_warehouse', $this->first->fresh()->status);
        $this->assertSame('000001', $this->load()->json('data.serial_no'));
    }

    public function test_vehicle_and_driver_must_be_free_active_and_valid(): void
    {
        $this->load()->assertCreated();
        $other = Shipment::factory()->create(['status' => 'at_main_warehouse']);

        $this->load(['shipment_ids' => [$other->id]])->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);

        $bus = Vehicle::create(['plate_no' => 'BUS 1', 'category' => 'bus', 'seats' => 52]);
        $this->load(['shipment_ids' => [$other->id], 'vehicle_id' => $bus->id])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');

        $freeTruck = Vehicle::create(['plate_no' => 'TRK 2', 'category' => 'single']);
        $this->load(['shipment_ids' => [$other->id], 'vehicle_id' => $freeTruck->id])->assertUnprocessable()->assertJsonValidationErrors('driver_id');

        $expired = Driver::create(['name' => 'Old', 'id_expiry_date' => now()->subDay()->toDateString()]);
        $this->load(['shipment_ids' => [$other->id], 'vehicle_id' => $freeTruck->id, 'driver_id' => $expired->id])
            ->assertUnprocessable()->assertJsonValidationErrors('driver_id');
    }

    public function test_only_warehouse_staff_can_load_trips(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);

        $this->load()->assertForbidden();
        $this->getJson('/api/v1/cargo-trips')->assertForbidden();
    }

    public function test_stages_move_forward_and_carry_the_shipment_statuses(): void
    {
        $id = $this->load()->json('data.id');

        $this->postJson("/api/v1/cargo-trips/{$id}/stage", ['stage' => 'loaded'])->assertUnprocessable();
        $this->postJson("/api/v1/cargo-trips/{$id}/stage", ['stage' => 'completed'])->assertUnprocessable();

        $this->postJson("/api/v1/cargo-trips/{$id}/stage", ['stage' => 'departed_cairo', 'notes' => 'left at 6am'])
            ->assertOk()->assertJsonPath('data.stage', 'departed_cairo');
        $this->assertNotNull(DB::table('cargo_trips')->where('id', $id)->value('departed_at'));
        $this->assertSame(2, DB::table('shipments')->where('status', 'in_transit')->count());

        $this->postJson("/api/v1/cargo-trips/{$id}/stage", ['stage' => 'crossing_border'])->assertOk();
        $this->postJson("/api/v1/cargo-trips/{$id}/stage", ['stage' => 'at_aswan'])->assertUnprocessable();

        $this->postJson("/api/v1/cargo-trips/{$id}/stage", ['stage' => 'arrived'])->assertOk();
        $this->assertSame(2, DB::table('shipments')->where('status', 'arrived')->count());
        $this->assertNotNull(DB::table('cargo_trips')->where('id', $id)->value('arrived_at'));

        $this->postJson("/api/v1/cargo-trips/{$id}/stage", ['stage' => 'completed'])->assertOk();
        $this->assertSame(2, DB::table('shipments')->where('status', 'arrived')->count());
        $this->assertSame(
            ['loaded', 'departed_cairo', 'crossing_border', 'arrived', 'completed'],
            DB::table('trip_stage_events')->where('trip_id', $id)->orderBy('id')->pluck('stage')->all(),
        );
    }

    public function test_cancelling_before_departure_returns_shipments_to_the_warehouse(): void
    {
        $id = $this->load()->json('data.id');

        $this->postJson("/api/v1/cargo-trips/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/cargo-trips/{$id}/cancel", ['reason' => 'truck broke down'])->assertOk()->assertJsonPath('data.stage', 'cancelled');

        $this->assertSame(2, DB::table('shipments')->where(['status' => 'at_main_warehouse', 'cargo_trip_id' => null])->count());
        $this->postJson("/api/v1/cargo-trips/{$id}/stage", ['stage' => 'departed_cairo'])->assertUnprocessable();

        $this->assertSame('000002', $this->load()->json('data.serial_no'));
    }

    public function test_a_departed_trip_cannot_be_cancelled_and_its_fingerprint_is_frozen(): void
    {
        $id = $this->load()->json('data.id');
        $replacement = UploadedFile::fake()->create('new.png', 10, 'image/png');
        $this->post("/api/v1/cargo-trips/{$id}/fingerprint", ['fingerprint' => $replacement], ['Accept' => 'application/json'])->assertOk();

        $this->postJson("/api/v1/cargo-trips/{$id}/stage", ['stage' => 'departed_cairo'])->assertOk();

        $this->postJson("/api/v1/cargo-trips/{$id}/cancel", ['reason' => 'late'])->assertUnprocessable();
        $this->post("/api/v1/cargo-trips/{$id}/fingerprint", ['fingerprint' => UploadedFile::fake()->create('x.png', 10, 'image/png')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_manifest_totals_and_waybill_sheets(): void
    {
        $id = $this->load()->json('data.id');

        $manifest = $this->getJson("/api/v1/cargo-trips/{$id}/manifest")->assertOk()
            ->assertJsonPath('data.totals.shipments', 2)
            ->assertJsonPath('data.totals.by_unit.carton', '14.00')
            ->assertJsonPath('data.totals.weight_kg', '150.00')
            ->assertJsonPath('data.over_capacity', false)
            ->assertJsonPath('data.undertaking.delivery_days', 5);
        $this->assertStringNotContainsString('price', $manifest->getContent());

        $waybills = $this->getJson("/api/v1/cargo-trips/{$id}/waybills")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.sheet.number', 1)
            ->assertJsonPath('data.1.sheet.of', 2)
            ->assertJsonPath('data.0.driver.name', 'Hassan');
        $this->assertNotNull($waybills->json('data.0.fingerprint_url'));
        $this->assertStringNotContainsString('price', $waybills->getContent());
    }

    public function test_overloaded_trucks_are_flagged_on_the_manifest(): void
    {
        $this->truck->update(['max_ton' => 0.1]);
        $id = $this->load()->json('data.id');

        $this->getJson("/api/v1/cargo-trips/{$id}/manifest")->assertJsonPath('data.over_capacity', true);
    }

    public function test_public_tracking_shows_progress_without_personal_or_money_data(): void
    {
        $id = $this->load()->json('data.id');
        $this->postJson("/api/v1/cargo-trips/{$id}/stage", ['stage' => 'departed_cairo'])->assertOk();
        $code = $this->first->fresh()->tracking_code;

        $body = $this->getJson('/api/v1/track/'.strtolower($code))->assertOk()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.trip_stages.1.stage', 'departed_cairo');

        $this->assertContains('loaded', collect($body->json('data.status_history'))->pluck('status')->all());
        $content = $body->getContent();
        foreach (['price', 'consignee', 'phone', 'customer', 'serial_no'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }

        $this->getJson('/api/v1/track/UNKNOWN123')->assertNotFound();
    }
}
