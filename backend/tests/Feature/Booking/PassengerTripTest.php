<?php

namespace Tests\Feature\Booking;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Core\Models\Agent;
use App\Modules\MasterData\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassengerTripTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'kind' => 'bus',
            'route' => 'القاهرة - الخرطوم',
            'depart_at' => now()->addDays(3)->toIso8601String(),
            'vehicle_id' => Vehicle::create(['plate_no' => 'BUS '.fake()->unique()->numerify('###'), 'category' => 'bus', 'seats' => 52])->id,
            'passenger_seats' => 48,
            'express_seats' => 4,
            'price' => '2500',
            'currency_id' => 1,
            ...$overrides,
        ];
    }

    public function test_the_warehouse_creates_a_bus_trip_and_capacity_comes_from_the_bus(): void
    {
        $this->signInAs('faisal_staff');

        $this->postJson('/api/v1/trips', $this->payload())->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.capacity', 52)
            ->assertJsonPath('data.booked_seats', 0)
            ->assertJsonPath('data.free_seats', 48)
            ->assertJsonPath('data.price', '2500.00');
    }

    public function test_seats_cannot_exceed_the_capacity_and_the_bus_must_be_a_bus(): void
    {
        $this->signInAs('faisal_staff');

        $this->postJson('/api/v1/trips', $this->payload(['passenger_seats' => 50, 'express_seats' => 5]))
            ->assertUnprocessable()->assertJsonValidationErrors('passenger_seats');

        $truck = Vehicle::create(['plate_no' => 'TRK 1', 'category' => 'jumbo']);
        $this->postJson('/api/v1/trips', $this->payload(['vehicle_id' => $truck->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');

        $this->postJson('/api/v1/trips', $this->payload(['vehicle_id' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('capacity');

        $this->postJson('/api/v1/trips', $this->payload(['vehicle_id' => null, 'kind' => 'flight', 'capacity' => 180, 'passenger_seats' => 100]))
            ->assertCreated();
    }

    public function test_a_trip_must_depart_in_the_future(): void
    {
        $this->signInAs('faisal_staff');

        $this->postJson('/api/v1/trips', $this->payload(['depart_at' => now()->subHour()->toIso8601String()]))
            ->assertUnprocessable()->assertJsonValidationErrors('depart_at');
    }

    public function test_only_trip_managers_can_create_but_booking_staff_can_view(): void
    {
        $trip = PassengerTrip::factory()->create();
        $this->signInAs('branch_staff');

        $this->postJson('/api/v1/trips', $this->payload())->assertForbidden();
        $this->getJson('/api/v1/trips')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/trips/{$trip->id}")->assertOk();

        $this->signInAs('agent', ['agent_id' => Agent::factory()->create()->id]);
        $this->getJson('/api/v1/trips')->assertOk();
        $this->putJson("/api/v1/trips/{$trip->id}", ['route' => 'x'])->assertForbidden();
    }

    public function test_listing_filters_by_status_kind_and_what_can_still_be_sold(): void
    {
        PassengerTrip::factory()->create(['route' => 'مفتوحة']);
        PassengerTrip::factory()->create(['route' => 'اتحركت', 'status' => 'departed']);
        PassengerTrip::factory()->create(['route' => 'طيران', 'kind' => 'flight']);
        PassengerTrip::factory()->create(['route' => 'فاتت', 'depart_at' => now()->subDay()]);
        $this->signInAs('branch_staff');

        $sellable = $this->getJson('/api/v1/trips?sellable=1&kind=bus')->assertOk();
        $this->assertSame(['مفتوحة'], collect($sellable->json('data'))->pluck('route')->all());
        $this->getJson('/api/v1/trips?status=departed')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_seats_cannot_be_cut_below_a_booked_seat_and_prices_can_change(): void
    {
        $trip = PassengerTrip::factory()->create();
        Booking::factory()->create(['trip_id' => $trip->id, 'seat_no' => 40]);
        $this->signInAs('faisal_staff');

        $this->putJson("/api/v1/trips/{$trip->id}", ['passenger_seats' => 30])
            ->assertUnprocessable()->assertJsonValidationErrors('passenger_seats');
        $this->putJson("/api/v1/trips/{$trip->id}", ['passenger_seats' => 44, 'price' => '2700'])
            ->assertOk()->assertJsonPath('data.passenger_seats', 44)->assertJsonPath('data.price', '2700.00');
    }

    public function test_departure_and_completion_follow_the_lifecycle(): void
    {
        $trip = PassengerTrip::factory()->create();
        $paid = Booking::factory()->create(['trip_id' => $trip->id, 'seat_no' => 1, 'status' => 'paid']);
        $cancelled = Booking::factory()->create(['trip_id' => $trip->id, 'seat_no' => 2, 'status' => 'cancelled']);
        $this->signInAs('faisal_staff');

        $this->postJson("/api/v1/trips/{$trip->id}/complete")->assertUnprocessable();
        $this->postJson("/api/v1/trips/{$trip->id}/depart")->assertOk()->assertJsonPath('data.status', 'departed');
        $this->postJson("/api/v1/trips/{$trip->id}/depart")->assertUnprocessable();
        $this->putJson("/api/v1/trips/{$trip->id}", ['route' => 'x'])->assertUnprocessable();

        $this->postJson("/api/v1/trips/{$trip->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame('travelled', $paid->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_a_trip_with_live_bookings_cannot_be_cancelled(): void
    {
        $busy = PassengerTrip::factory()->create();
        Booking::factory()->create(['trip_id' => $busy->id, 'seat_no' => 1]);
        $empty = PassengerTrip::factory()->create();
        $this->signInAs('faisal_staff');

        $this->postJson("/api/v1/trips/{$busy->id}/cancel")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/trips/{$empty->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_express_seats_freeze_after_the_cutoff(): void
    {
        $due = PassengerTrip::factory()->create(['express_cutoff_at' => now()->subMinute()]);
        $later = PassengerTrip::factory()->create(['express_cutoff_at' => now()->addDay()]);
        $none = PassengerTrip::factory()->create(['express_cutoff_at' => null]);

        $this->artisan('gomana:lock-express-trips')->assertSuccessful();

        $this->assertSame('express_locked', $due->fresh()->status);
        $this->assertSame('open', $later->fresh()->status);
        $this->assertSame('open', $none->fresh()->status);
    }

    public function test_the_passenger_list_is_in_seat_order_and_skips_cancelled_bookings(): void
    {
        $trip = PassengerTrip::factory()->create();
        Booking::factory()->create(['trip_id' => $trip->id, 'seat_no' => 9, 'passenger_name' => 'ثانيا']);
        Booking::factory()->create(['trip_id' => $trip->id, 'seat_no' => 2, 'passenger_name' => 'أولا']);
        Booking::factory()->create(['trip_id' => $trip->id, 'seat_no' => 5, 'passenger_name' => 'ملغي', 'status' => 'cancelled']);
        $this->signInAs('faisal_staff');

        $response = $this->getJson("/api/v1/trips/{$trip->id}/passengers")->assertOk();

        $this->assertSame(['أولا', 'ثانيا'], collect($response->json('data'))->pluck('passenger_name')->all());
    }
}
