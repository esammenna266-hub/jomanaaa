<?php

namespace Tests\Feature\Shipping;

use App\Modules\Core\Models\Branch;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
    }

    private function shipmentAtBranch(?Branch $branch = null, int $qty = 10): Shipment
    {
        $shipment = Shipment::factory()->atBranch($branch ?? $this->branch)->create();
        ShipmentItem::create(['shipment_id' => $shipment->id, 'name' => 'Clothes', 'qty' => $qty, 'unit' => 'carton']);

        return $shipment;
    }

    /**
     * @return array<string, mixed>
     */
    private function sendPayload(Shipment ...$shipments): array
    {
        return [
            'shipments' => array_map(fn (Shipment $shipment) => ['shipment_id' => $shipment->id], $shipments),
            'vehicle_text' => 'Truck 123',
            'driver_text' => 'Hassan',
            'driver_phone' => '01012345678',
        ];
    }

    public function test_sending_issues_a_numbered_form_and_moves_shipments(): void
    {
        [$first, $second] = [$this->shipmentAtBranch(qty: 10), $this->shipmentAtBranch(qty: 4)];
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/v1/transfers', $this->sendPayload($first, $second))->assertCreated()
            ->assertJsonPath('data.serial_no', '000001')
            ->assertJsonPath('data.barcode', '000001')
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.driver_phone', '+201012345678')
            ->assertJsonPath('data.to_branch.name', 'مخزن فيصل');

        $this->assertSame(['10.00', '4.00'], collect($response->json('data.items'))->pluck('sent_count')->all());
        $this->assertSame(2, DB::table('shipments')->where('status', 'to_main_warehouse')->count());
    }

    public function test_only_received_shipments_of_the_senders_branch_can_be_sent(): void
    {
        $draft = Shipment::factory()->create(['branch_id' => $this->branch->id]);
        $foreign = $this->shipmentAtBranch(Branch::factory()->create());
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $this->postJson('/api/v1/transfers', $this->sendPayload($draft))->assertUnprocessable()->assertJsonValidationErrors('shipments');
        $this->postJson('/api/v1/transfers', $this->sendPayload($foreign))->assertUnprocessable()->assertJsonValidationErrors('shipments');

        $ok = $this->shipmentAtBranch();
        $payload = $this->sendPayload($ok);
        $payload['shipments'][] = $payload['shipments'][0];
        $this->postJson('/api/v1/transfers', $payload)->assertUnprocessable();

        $this->postJson('/api/v1/transfers', $this->sendPayload($ok))->assertCreated();
        $this->postJson('/api/v1/transfers', $this->sendPayload($ok))->assertUnprocessable();
    }

    public function test_main_warehouse_cannot_send_to_itself(): void
    {
        $main = Branch::where('is_main_warehouse', true)->firstOrFail();
        $shipment = $this->shipmentAtBranch($main);
        $this->signInAs('owner');

        $this->postJson('/api/v1/transfers', [...$this->sendPayload($shipment), 'from_branch_id' => $main->id])->assertUnprocessable();
    }

    public function test_scanning_finds_the_open_transfer_by_form_or_shipment_code(): void
    {
        $shipment = $this->shipmentAtBranch();
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $this->postJson('/api/v1/transfers', $this->sendPayload($shipment))->assertCreated();

        $this->signInAs('faisal_staff', ['branch_id' => Branch::where('is_main_warehouse', true)->value('id')]);
        $this->getJson('/api/v1/transfers/lookup?code=000001')->assertOk()->assertJsonPath('data.serial_no', '000001');
        $this->getJson('/api/v1/transfers/lookup?code='.$shipment->tracking_code)->assertOk()->assertJsonPath('data.serial_no', '000001');
        $this->getJson('/api/v1/transfers/lookup?code=NOPE')->assertNotFound();
    }

    public function test_receiving_matching_counts_completes_the_transfer(): void
    {
        [$first, $second] = [$this->shipmentAtBranch(qty: 10), $this->shipmentAtBranch(qty: 4)];
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $id = $this->postJson('/api/v1/transfers', $this->sendPayload($first, $second))->json('data.id');

        $this->postJson("/api/v1/transfers/{$id}/receive", ['items' => []])->assertForbidden();

        $this->signInAs('faisal_staff');
        $this->postJson("/api/v1/transfers/{$id}/receive", ['items' => [
            ['shipment_id' => $first->id, 'received_count' => 10],
            ['shipment_id' => $second->id, 'received_count' => 4],
        ]])->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.has_difference', false);

        $this->assertSame(2, DB::table('shipments')->where('status', 'at_main_warehouse')->count());
        $this->postJson("/api/v1/transfers/{$id}/receive", ['items' => [
            ['shipment_id' => $first->id, 'received_count' => 10],
            ['shipment_id' => $second->id, 'received_count' => 4],
        ]])->assertUnprocessable();
    }

    public function test_shortage_is_flagged_and_unreceived_shipments_stay_in_transit(): void
    {
        [$first, $second] = [$this->shipmentAtBranch(qty: 10), $this->shipmentAtBranch(qty: 4)];
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $id = $this->postJson('/api/v1/transfers', $this->sendPayload($first, $second))->json('data.id');

        $this->signInAs('faisal_staff');
        $this->postJson("/api/v1/transfers/{$id}/receive", ['items' => [['shipment_id' => $first->id, 'received_count' => 10]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $response = $this->postJson("/api/v1/transfers/{$id}/receive", ['items' => [
            ['shipment_id' => $first->id, 'received_count' => 8],
            ['shipment_id' => $second->id, 'received_count' => 0],
        ]])->assertOk()->assertJsonPath('data.status', 'received_with_diff')->assertJsonPath('data.has_difference', true);

        $this->assertSame(['-2.00', '-4.00'], collect($response->json('data.items'))->pluck('difference')->all());
        $this->assertSame('at_main_warehouse', $first->fresh()->status);
        $this->assertSame('to_main_warehouse', $second->fresh()->status);
        $this->getJson('/api/v1/transfers?status=received_with_diff')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_branch_staff_only_see_their_own_transfers(): void
    {
        $shipment = $this->shipmentAtBranch();
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $id = $this->postJson('/api/v1/transfers', $this->sendPayload($shipment))->json('data.id');
        $this->getJson('/api/v1/transfers')->assertOk()->assertJsonCount(1, 'data');

        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);
        $this->getJson('/api/v1/transfers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/transfers/{$id}")->assertNotFound();

        $this->signInAs('accountant');
        $this->getJson('/api/v1/transfers')->assertForbidden();
    }
}
