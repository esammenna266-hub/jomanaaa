<?php

namespace Tests\Feature\Booking;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Identity\Models\User;
use App\Modules\MasterData\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeatBookingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Branch $branch;

    private Treasury $cash;

    private User $staff;

    private PassengerTrip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->cash = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'cash']);
        $this->staff = User::factory()->withRole('branch_staff')->create(['branch_id' => $this->branch->id]);
        $this->trip = PassengerTrip::factory()->create();
        $this->actingAs($this->staff, 'sanctum');
    }

    private function balance(): string
    {
        return (string) DB::table('v_treasury_balances')->where('treasury_id', $this->cash->id)->value('balance');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(array $overrides = [])
    {
        return $this->postJson('/api/v1/bookings', [
            'trip_id' => $this->trip->id,
            'seat_no' => 5,
            'passenger_name' => 'سعد محمود',
            'passport_no' => 'A1234567',
            'destination' => 'أم درمان',
            'treasury_id' => $this->cash->id,
            ...$overrides,
        ]);
    }

    public function test_booking_a_seat_collects_the_price_and_numbers_the_booking(): void
    {
        $this->book()->assertCreated()
            ->assertJsonPath('data.serial_no', '000001')
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.seat_no', 5)
            ->assertJsonPath('data.sale_price', '2500.00')
            ->assertJsonPath('data.paid', '2500.00')
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonMissingPath('data.agent_price');

        $this->assertEquals(2500, $this->balance());
        $this->assertDatabaseHas('payments', ['kind' => 'collection', 'direction' => 'in', 'currency_id' => 1]);
    }

    public function test_a_taken_seat_is_refused_and_leaves_no_money_behind(): void
    {
        $this->book()->assertCreated();
        $second = User::factory()->withRole('branch_staff')->create(['branch_id' => $this->branch->id]);
        $this->actingAs($second, 'sanctum');

        $this->book(['passenger_name' => 'غيره'])->assertUnprocessable()->assertJsonValidationErrors('seat_no');

        $this->assertSame(1, Booking::count());
        $this->assertEquals(2500, $this->balance());
        $this->book(['seat_no' => 6])->assertCreated();
    }

    public function test_the_database_itself_refuses_a_second_live_booking_for_a_seat(): void
    {
        $this->book()->assertCreated();

        $this->expectException(UniqueConstraintViolationException::class);

        Booking::factory()->create(['trip_id' => $this->trip->id, 'seat_no' => 5]);
    }

    public function test_seat_numbers_must_exist_on_the_trip(): void
    {
        $this->book(['seat_no' => 0])->assertUnprocessable();
        $this->book(['seat_no' => 49])->assertUnprocessable()->assertJsonValidationErrors('seat_no');
        $this->book(['seat_no' => 48])->assertCreated();
    }

    public function test_sales_close_for_departed_cancelled_and_past_trips(): void
    {
        foreach (['departed', 'cancelled', 'completed'] as $status) {
            $trip = PassengerTrip::factory()->create(['status' => $status]);
            $this->book(['trip_id' => $trip->id])->assertUnprocessable()->assertJsonValidationErrors('trip_id');
        }

        $past = PassengerTrip::factory()->create(['depart_at' => now()->subHour()]);
        $this->book(['trip_id' => $past->id])->assertUnprocessable();

        $locked = PassengerTrip::factory()->create(['status' => 'express_locked']);
        $this->book(['trip_id' => $locked->id])->assertCreated();
    }

    public function test_the_paying_treasury_must_belong_to_the_branch_match_the_currency_and_be_active(): void
    {
        $foreign = Treasury::create(['branch_id' => Branch::factory()->create()->id, 'currency_id' => 1, 'method' => 'cash']);
        $usd = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 2, 'method' => 'cash']);
        $stopped = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'instapay', 'active' => false]);

        $this->book(['treasury_id' => null])->assertUnprocessable()->assertJsonValidationErrors('treasury_id');
        $this->book(['treasury_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('treasury_id');
        $this->book(['treasury_id' => $usd->id])->assertUnprocessable()->assertJsonValidationErrors('treasury_id');
        $this->book(['treasury_id' => $stopped->id])->assertUnprocessable()->assertJsonValidationErrors('treasury_id');

        $this->assertSame(0, Booking::count());
    }

    public function test_staff_cannot_set_their_own_price(): void
    {
        $this->book(['sale_price' => '100'])->assertCreated()->assertJsonPath('data.sale_price', '2500.00');
    }

    public function test_a_customer_outside_the_users_scope_cannot_be_attached(): void
    {
        $foreign = Customer::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $mine = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $this->book(['customer_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->book(['customer_id' => $mine->id])->assertCreated()->assertJsonPath('data.customer_id', $mine->id);
        $this->assertDatabaseHas('payments', ['payer_type' => 'customer', 'payer_id' => $mine->id]);
    }

    public function test_cancelling_refunds_the_seat_money_and_frees_the_seat(): void
    {
        $id = $this->book()->json('data.id');

        $this->postJson("/api/v1/bookings/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/bookings/{$id}/cancel", ['reason' => 'الراكب غيّر رأيه'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.paid', '0.00');

        $this->assertEquals(0, $this->balance());
        $this->assertSame(1, DB::table('payments')->where(['kind' => 'refund', 'direction' => 'out'])->count());
        $this->postJson("/api/v1/bookings/{$id}/cancel", ['reason' => 'تاني'])->assertUnprocessable();
        $this->book(['passenger_name' => 'راكب جديد'])->assertCreated();
    }

    public function test_a_departed_trips_bookings_cannot_be_cancelled(): void
    {
        $id = $this->book()->json('data.id');
        $this->trip->update(['status' => 'departed']);

        $this->postJson("/api/v1/bookings/{$id}/cancel", ['reason' => 'متأخر'])->assertUnprocessable();
        $this->assertEquals(2500, $this->balance());
    }

    public function test_cancelling_is_limited_to_the_users_branch(): void
    {
        $id = $this->book()->json('data.id');
        $other = User::factory()->withRole('branch_staff')->create(['branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($other, 'sanctum');

        $this->postJson("/api/v1/bookings/{$id}/cancel", ['reason' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/bookings/{$id}")->assertNotFound();
        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_external_offices_book_on_account_with_their_own_price_and_never_pay_at_booking(): void
    {
        $office = Agent::factory()->create();
        $agentUser = User::factory()->withRole('agent')->create(['agent_id' => $office->id]);
        $this->actingAs($agentUser, 'sanctum');

        $response = $this->book(['treasury_id' => null, 'sale_price' => '3000'])->assertCreated()
            ->assertJsonPath('data.status', 'reserved')
            ->assertJsonPath('data.sale_price', '3000.00')
            ->assertJsonPath('data.paid', '0.00')
            ->assertJsonPath('data.agent_id', $office->id)
            ->assertJsonMissingPath('data.agent_price');

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertEquals(2500, Booking::find($response->json('data.id'))->makeVisible('agent_price')->agent_price);

        $this->book(['seat_no' => 6, 'treasury_id' => null])->assertCreated()->assertJsonPath('data.sale_price', '2500.00');
    }

    public function test_offices_see_only_their_bookings_and_cannot_cancel(): void
    {
        $mine = Agent::factory()->create();
        $rival = Agent::factory()->create();
        Booking::factory()->create(['trip_id' => $this->trip->id, 'seat_no' => 1, 'agent_id' => $mine->id, 'status' => 'reserved']);
        Booking::factory()->create(['trip_id' => $this->trip->id, 'seat_no' => 2, 'agent_id' => $rival->id, 'status' => 'reserved']);
        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => $mine->id]), 'sanctum');

        $response = $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/bookings/'.$response->json('data.0.id').'/cancel', ['reason' => 'x'])->assertForbidden();
    }

    public function test_search_finds_bookings_by_passenger_passport_or_serial(): void
    {
        $this->book()->assertCreated();
        $this->book(['seat_no' => 6, 'passenger_name' => 'منى يوسف', 'passport_no' => 'B999'])->assertCreated();

        $this->getJson('/api/v1/bookings?q=منى')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/bookings?q=A1234567')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/bookings?q=000002')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/bookings?trip_id='.$this->trip->id)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_seat_map_shows_taken_seats_with_passengers_but_hides_who_sold_them_from_staff(): void
    {
        $this->book()->assertCreated();
        $map = $this->getJson("/api/v1/trips/{$this->trip->id}/seats")->assertOk();

        $this->assertCount(48, $map->json('data.seats'));
        $this->assertSame('free', $map->json('data.seats.0.status'));
        $seat = $map->json('data.seats.4');
        $this->assertSame('booked', $seat['status']);
        $this->assertSame('سعد محمود', $seat['booking']['passenger_name']);
        $this->assertSame('2500.00', $seat['booking']['paid']);
        $this->assertArrayNotHasKey('booked_by', $seat);
        $map->assertJsonPath('data.trip.booked_seats', 1)->assertJsonPath('data.trip.free_seats', 47);

        $this->signInAs('owner');
        $ownerSeat = $this->getJson("/api/v1/trips/{$this->trip->id}/seats")->json('data.seats.4');
        $this->assertSame($this->branch->name, $ownerSeat['booked_by']['branch']);
    }

    public function test_cancelled_bookings_free_their_seat_on_the_map(): void
    {
        $id = $this->book()->json('data.id');
        $this->postJson("/api/v1/bookings/{$id}/cancel", ['reason' => 'x'])->assertOk();

        $this->assertSame('free', $this->getJson("/api/v1/trips/{$this->trip->id}/seats")->json('data.seats.4.status'));
    }
}
