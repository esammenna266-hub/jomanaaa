<?php

namespace Tests\Feature\Booking;

use App\Modules\Booking\Models\ServiceBooking;
use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Identity\Models\User;
use App\Modules\MasterData\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hotel and car bookings (open point 14): free-form details and a price, paid through the same ledger.
 */
class ServiceBookingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Branch $branch;

    private Treasury $treasury;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->treasury = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'cash']);
        $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'type' => 'hotel',
            'customer_id' => $this->customer->id,
            'details' => 'فندق النيل، غرفتين، 3 ليالي',
            'start_date' => '2099-12-01',
            'end_date' => '2099-12-04',
            'price' => '4500',
            'currency_id' => 1,
            ...$overrides,
        ];
    }

    private function staff(): User
    {
        return $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
    }

    private function balance(): float
    {
        return (float) DB::table('payments')->where('treasury_id', $this->treasury->id)
            ->selectRaw("COALESCE(SUM(CASE direction WHEN 'in' THEN amount ELSE -amount END), 0) AS net")->value('net');
    }

    public function test_a_booking_with_a_treasury_is_paid_in_full_at_once(): void
    {
        $this->staff();

        $this->postJson('/api/v1/service-bookings', $this->payload(['treasury_id' => $this->treasury->id]))->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid', '4500.00')
            ->assertJsonPath('data.price', '4500.00')
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.customer.id', $this->customer->id);

        $this->assertEquals(4500, $this->balance());
        $this->assertDatabaseHas('payments', ['kind' => 'collection', 'direction' => 'in', 'payer_type' => 'customer']);
    }

    public function test_without_a_treasury_the_booking_waits_and_can_be_collected_later_only_once(): void
    {
        $this->staff();
        $id = $this->postJson('/api/v1/service-bookings', $this->payload(['type' => 'car', 'details' => 'سيارة مع سواق، يومين']))->assertCreated()
            ->assertJsonPath('data.status', 'reserved')
            ->assertJsonPath('data.paid', '0.00')
            ->json('data.id');
        $this->assertSame(0, DB::table('payments')->count());

        $this->postJson("/api/v1/service-bookings/{$id}/collect", ['treasury_id' => $this->treasury->id])->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid', '4500.00');
        $this->postJson("/api/v1/service-bookings/{$id}/collect", ['treasury_id' => $this->treasury->id])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertEquals(4500, $this->balance());
    }

    public function test_the_treasury_must_be_active_in_the_bookings_currency_and_branch(): void
    {
        $usd = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 2, 'method' => 'cash']);
        $elsewhere = Treasury::create(['branch_id' => Branch::factory()->create()->id, 'currency_id' => 1, 'method' => 'cash']);
        $stopped = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'instapay', 'active' => false]);
        $this->staff();

        foreach ([$usd, $elsewhere, $stopped] as $wrong) {
            $this->postJson('/api/v1/service-bookings', $this->payload(['treasury_id' => $wrong->id]))
                ->assertUnprocessable()->assertJsonValidationErrors('treasury_id');
        }

        $this->assertSame(0, ServiceBooking::count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_validation_covers_the_type_the_price_and_the_dates(): void
    {
        $this->staff();

        $this->postJson('/api/v1/service-bookings', $this->payload(['type' => 'plane']))->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/v1/service-bookings', $this->payload(['price' => '0']))->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->postJson('/api/v1/service-bookings', $this->payload(['details' => '']))->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->postJson('/api/v1/service-bookings', $this->payload(['end_date' => '2099-11-30']))->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson('/api/v1/service-bookings', $this->payload(['start_date' => null, 'end_date' => null]))->assertCreated();
    }

    public function test_cancelling_refunds_what_was_collected_and_keeps_the_reason(): void
    {
        $this->staff();
        $id = $this->postJson('/api/v1/service-bookings', $this->payload(['treasury_id' => $this->treasury->id]))->json('data.id');

        $this->postJson("/api/v1/service-bookings/{$id}/cancel", ['reason' => 'العميل لغى السفر'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'العميل لغى السفر')
            ->assertJsonPath('data.paid', '0.00');

        $this->assertEquals(0, $this->balance());
        $this->assertSame(2, DB::table('payments')->count());
        $this->postJson("/api/v1/service-bookings/{$id}/cancel", ['reason' => 'تاني'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(2, DB::table('payments')->count());
    }

    public function test_a_paid_booking_can_be_completed_but_a_reserved_one_cannot(): void
    {
        $this->staff();
        $paid = $this->postJson('/api/v1/service-bookings', $this->payload(['treasury_id' => $this->treasury->id]))->json('data.id');
        $reserved = $this->postJson('/api/v1/service-bookings', $this->payload())->json('data.id');

        $this->postJson("/api/v1/service-bookings/{$reserved}/complete")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/service-bookings/{$paid}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/v1/service-bookings/{$paid}/cancel", ['reason' => 'x'])->assertUnprocessable();
    }

    public function test_staff_see_their_branch_and_offices_only_their_own_and_offices_cannot_collect(): void
    {
        $office = Agent::factory()->create();
        $rival = Agent::factory()->create();
        ServiceBooking::create([
            'serial_no' => 'S1', 'type' => 'hotel', 'customer_id' => $this->customer->id, 'agent_id' => $office->id,
            'details' => 'x', 'price' => '100', 'currency_id' => 1, 'status' => 'reserved',
        ]);
        ServiceBooking::create([
            'serial_no' => 'S2', 'type' => 'car', 'customer_id' => $this->customer->id, 'agent_id' => $rival->id,
            'details' => 'y', 'price' => '100', 'currency_id' => 1, 'status' => 'reserved',
        ]);
        ServiceBooking::create([
            'serial_no' => 'S3', 'type' => 'car', 'customer_id' => $this->customer->id, 'branch_id' => Branch::factory()->create()->id,
            'details' => 'z', 'price' => '100', 'currency_id' => 1, 'status' => 'reserved',
        ]);

        $this->staff();
        $this->getJson('/api/v1/service-bookings')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => $office->id]), 'sanctum');
        $list = $this->getJson('/api/v1/service-bookings')->assertOk()->assertJsonCount(1, 'data');
        $id = $list->json('data.0.id');
        $this->postJson("/api/v1/service-bookings/{$id}/collect", ['treasury_id' => $this->treasury->id])->assertForbidden();
        $this->postJson("/api/v1/service-bookings/{$id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/service-bookings/'.ServiceBooking::where('serial_no', 'S2')->value('id'))->assertNotFound();
    }

    public function test_an_office_books_on_account_and_cannot_pay_into_a_treasury(): void
    {
        $office = Agent::factory()->create();
        $customer = Customer::factory()->create(['agent_id' => $office->id]);
        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => $office->id]), 'sanctum');

        $this->postJson('/api/v1/service-bookings', $this->payload(['customer_id' => $customer->id, 'treasury_id' => $this->treasury->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('treasury_id');
        $this->postJson('/api/v1/service-bookings', $this->payload(['customer_id' => $customer->id]))->assertCreated()
            ->assertJsonPath('data.status', 'reserved')->assertJsonPath('data.agent_id', $office->id);
        $this->postJson('/api/v1/service-bookings', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    public function test_search_finds_a_booking_by_serial_details_or_customer(): void
    {
        $this->staff();
        $this->postJson('/api/v1/service-bookings', $this->payload())->assertCreated();
        $this->postJson('/api/v1/service-bookings', $this->payload(['type' => 'car', 'details' => 'سيارة لأسوان']))->assertCreated();

        $this->getJson('/api/v1/service-bookings?q=أسوان')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/service-bookings?q='.urlencode($this->customer->name))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/service-bookings?type=hotel')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/service-bookings?status=paid')->assertOk()->assertJsonCount(0, 'data');
    }
}
