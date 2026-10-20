<?php

namespace Tests\Feature\Trips;

use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Driver;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Trips\Models\CargoTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpenseAndDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private CargoTrip $trip;

    /** @var list<Shipment> */
    private array $shipments;

    private Treasury $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trip = CargoTrip::create([
            'serial_no' => '000001',
            'vehicle_id' => Vehicle::create(['plate_no' => 'TRK 1', 'category' => 'jumbo'])->id,
            'driver_id' => Driver::create(['name' => 'Hassan'])->id,
            'stage' => 'loaded',
        ]);
        $this->shipments = Shipment::factory()->count(3)->create(['status' => 'loaded', 'cargo_trip_id' => $this->trip->id])->all();
        $this->cash = Treasury::create([
            'branch_id' => Branch::where('is_main_warehouse', true)->value('id'), 'currency_id' => 1, 'method' => 'cash',
        ]);
        app(LedgerService::class)->post($this->cash, 'in', 'manual', '5000.00');
    }

    private function balance(): string
    {
        return (string) DB::table('v_treasury_balances')->where('treasury_id', $this->cash->id)->value('balance');
    }

    /**
     * @return array<string, mixed>
     */
    private function expense(array $overrides = []): array
    {
        return ['category' => 'Fuel', 'point' => 'Aswan', 'treasury_id' => $this->cash->id, 'amount' => '100.00', ...$overrides];
    }

    public function test_expense_is_paid_from_the_treasury_and_split_evenly_to_the_cent(): void
    {
        $this->signInAs('faisal_staff');

        $response = $this->postJson("/api/v1/cargo-trips/{$this->trip->id}/expenses", $this->expense())->assertCreated();

        $this->assertSame(['33.34', '33.33', '33.33'], collect($response->json('data.allocations'))->pluck('amount')->all());
        $this->assertEquals(4900, $this->balance());
        $this->assertSame(1, DB::table('payments')->where('kind', 'expense')->count());
        $this->assertSame(0, DB::table('v_expense_allocation_gaps')->count());
    }

    public function test_manual_allocations_must_add_up_and_belong_to_the_trip(): void
    {
        $this->signInAs('accountant');
        $url = "/api/v1/cargo-trips/{$this->trip->id}/expenses";
        [$a, $b] = $this->shipments;

        $this->postJson($url, $this->expense(['allocations' => [
            ['shipment_id' => $a->id, 'amount' => '60'], ['shipment_id' => $b->id, 'amount' => '30'],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('allocations');

        $stranger = Shipment::factory()->create();
        $this->postJson($url, $this->expense(['allocations' => [
            ['shipment_id' => $a->id, 'amount' => '50'], ['shipment_id' => $stranger->id, 'amount' => '50'],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('allocations');

        $this->postJson($url, $this->expense(['allocations' => [
            ['shipment_id' => $a->id, 'amount' => '70.50'], ['shipment_id' => $b->id, 'amount' => '29.50'],
        ]]))->assertCreated();

        $this->assertEquals(4900, $this->balance());
        $this->assertSame(2, DB::table('expense_allocations')->count());
    }

    public function test_failed_expenses_leave_no_money_movement(): void
    {
        $this->signInAs('faisal_staff');
        $this->postJson("/api/v1/cargo-trips/{$this->trip->id}/expenses", $this->expense([
            'allocations' => [['shipment_id' => $this->shipments[0]->id, 'amount' => '1']],
        ]))->assertUnprocessable();

        $this->assertEquals(5000, $this->balance());
        $this->assertSame(0, DB::table('trip_expenses')->count());
    }

    public function test_voiding_restores_the_treasury_and_hides_the_expense_from_the_shipment_file(): void
    {
        $this->signInAs('faisal_staff');
        $id = $this->postJson("/api/v1/cargo-trips/{$this->trip->id}/expenses", $this->expense())->json('data.id');
        $shipment = $this->shipments[0];

        $this->getJson("/api/v1/shipments/{$shipment->id}")->assertOk()->assertJsonCount(1, 'data.expense_allocations');

        $paymentId = DB::table('trip_expenses')->where('id', $id)->value('payment_id');
        $this->signInAs('accountant');
        $this->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->postJson("/api/v1/expenses/{$id}/void", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/expenses/{$id}/void", ['reason' => 'wrong truck'])->assertOk();
        $this->postJson("/api/v1/expenses/{$id}/void", ['reason' => 'again'])->assertUnprocessable();

        $this->assertEquals(5000, $this->balance());
        $this->assertNotNull(DB::table('trip_expenses')->where('id', $id)->value('voided_at'));
        $this->getJson("/api/v1/shipments/{$shipment->id}")->assertJsonCount(0, 'data.expense_allocations');
    }

    public function test_external_offices_never_see_company_expenses(): void
    {
        $office = Agent::factory()->create();
        $shipment = Shipment::factory()->create([
            'status' => 'loaded', 'cargo_trip_id' => $this->trip->id, 'agent_id' => $office->id,
            'customer_id' => Customer::factory()->create(['agent_id' => $office->id]),
        ]);
        $this->signInAs('faisal_staff');
        $this->postJson("/api/v1/cargo-trips/{$this->trip->id}/expenses", $this->expense())->assertCreated();

        $this->signInAs('agent', ['agent_id' => $office->id]);
        $this->getJson("/api/v1/shipments/{$shipment->id}")->assertOk()->assertJsonMissingPath('data.expense_allocations');
        $this->getJson("/api/v1/cargo-trips/{$this->trip->id}/expenses")->assertForbidden();
    }

    public function test_expenses_need_the_permission_a_live_trip_and_shipments(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);
        $this->postJson("/api/v1/cargo-trips/{$this->trip->id}/expenses", $this->expense())->assertForbidden();

        $this->signInAs('faisal_staff');
        $empty = CargoTrip::create([
            'serial_no' => '000002', 'vehicle_id' => Vehicle::create(['plate_no' => 'TRK 2', 'category' => 'single'])->id,
            'driver_id' => Driver::create(['name' => 'Ali'])->id, 'stage' => 'loaded',
        ]);
        $this->postJson("/api/v1/cargo-trips/{$empty->id}/expenses", $this->expense())->assertUnprocessable();

        $this->trip->update(['stage' => 'cancelled']);
        $this->postJson("/api/v1/cargo-trips/{$this->trip->id}/expenses", $this->expense())->assertUnprocessable();
    }

    public function test_delays_are_recorded_without_touching_money(): void
    {
        $this->signInAs('faisal_staff');
        $url = "/api/v1/cargo-trips/{$this->trip->id}/delays";

        $this->postJson($url, ['days' => 2, 'cost' => '50', 'responsible' => 'customer'])->assertUnprocessable()->assertJsonValidationErrors('currency_id');
        $this->postJson($url, ['days' => 2, 'responsible' => 'nobody'])->assertUnprocessable();
        $this->postJson($url, ['days' => 2, 'cost' => '50', 'currency_id' => 1, 'responsible' => 'customer', 'notes' => 'border closed'])
            ->assertCreated()->assertJsonPath('data.cost', '50.00');
        $this->postJson($url, ['days' => 1, 'responsible' => 'company'])->assertCreated()->assertJsonPath('data.cost', '0.00');

        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->assertEquals(5000, $this->balance());
    }

    private function arrivedShipment(string $paid): Shipment
    {
        $shipment = Shipment::factory()->create(['status' => 'arrived', 'cargo_trip_id' => $this->trip->id, 'price' => '1000.00']);
        if ((float) $paid > 0) {
            app(LedgerService::class)->post($this->cash, 'in', 'collection', $paid, ['shipment_id' => $shipment->id]);
        }

        return $shipment;
    }

    public function test_delivery_requires_the_balance_to_be_settled(): void
    {
        $owing = $this->arrivedShipment('400');
        $settled = $this->arrivedShipment('1000');
        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);

        $this->postJson("/api/v1/shipments/{$owing->id}/deliver", ['receiver_name' => 'Mohamed'])
            ->assertUnprocessable()->assertJsonValidationErrors('balance');
        $this->postJson("/api/v1/shipments/{$settled->id}/deliver", [])->assertUnprocessable()->assertJsonValidationErrors('receiver_name');

        DB::table('installments')->insert([
            'shipment_id' => $settled->id, 'due_date' => now()->addWeek()->toDateString(), 'amount' => 10, 'currency_id' => 1, 'status' => 'due',
        ]);
        $this->postJson("/api/v1/shipments/{$settled->id}/deliver", ['receiver_name' => 'Mohamed'])
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.delivered_to', 'Mohamed');

        $this->assertNotNull($settled->fresh()->delivered_at);
        $this->assertSame('cancelled', DB::table('installments')->where('shipment_id', $settled->id)->value('status'));
        $this->postJson("/api/v1/shipments/{$settled->id}/deliver", ['receiver_name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_shipments_that_have_not_arrived_cannot_be_delivered(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);

        $this->postJson("/api/v1/shipments/{$this->shipments[0]->id}/deliver", ['receiver_name' => 'X'])->assertUnprocessable();
    }

    public function test_destination_staff_find_arrived_shipments_from_any_branch(): void
    {
        $arrived = $this->arrivedShipment('250');
        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);

        $this->getJson('/api/v1/shipments/arrived?q='.strtolower($arrived->tracking_code))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.balance.remaining', '750.00');
        $this->getJson('/api/v1/shipments/arrived?q='.$this->shipments[0]->tracking_code)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/shipments/arrived?q=ab')->assertUnprocessable();

        $this->signInAs('agent', ['agent_id' => Agent::factory()->create()->id]);
        $this->getJson('/api/v1/shipments/arrived?q='.$arrived->tracking_code)->assertForbidden();
    }
}
