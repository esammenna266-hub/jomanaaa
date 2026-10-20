<?php

namespace Tests\Feature\Shipping;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Treasury;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Express shipments riding a bus trip (open point 2) and the release of unused express seats after the
 * cutoff (open point 3, BS-09).
 */
class ExpressSeatsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Branch $branch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'customer_id' => $this->customer->id,
            'type' => 'express',
            'consignee_name' => 'Mohamed',
            'consignee_phone' => '0912345678',
            'destination_id' => DB::table('destinations')->value('id'),
            'currency_id' => 1,
            'price' => '300.00',
            'items' => [['name' => 'Documents', 'qty' => 1, 'unit' => 'pack']],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function express(PassengerTrip $trip, array $overrides = []): Shipment
    {
        return Shipment::factory()->create([
            'branch_id' => $this->branch->id,
            'type' => 'express',
            'bus_trip_id' => $trip->id,
            ...$overrides,
        ]);
    }

    public function test_an_express_shipment_takes_an_express_seat_of_the_bus_trip(): void
    {
        $trip = PassengerTrip::factory()->create(['express_seats' => 2]);
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $this->postJson('/api/v1/shipments', $this->payload(['bus_trip_id' => $trip->id]))->assertCreated()
            ->assertJsonPath('data.bus_trip_id', $trip->id)
            ->assertJsonPath('data.bus_trip.route', $trip->route);

        $this->getJson("/api/v1/trips/{$trip->id}")->assertOk()
            ->assertJsonPath('data.express_seats', 2)
            ->assertJsonPath('data.express_used', 1)
            ->assertJsonPath('data.express_free', 1)
            ->assertJsonPath('data.express_released', 0)
            ->assertJsonPath('data.express_locked', false)
            ->assertJsonPath('data.free_seats', 48);
    }

    public function test_only_express_shipments_can_name_a_trip_and_the_trip_must_be_a_bus(): void
    {
        $trip = PassengerTrip::factory()->create();
        $flight = PassengerTrip::factory()->create(['kind' => 'flight']);
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $this->postJson('/api/v1/shipments', $this->payload(['type' => 'normal', 'bus_trip_id' => $trip->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('bus_trip_id');
        $this->postJson('/api/v1/shipments', $this->payload(['bus_trip_id' => $flight->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('bus_trip_id');
        $this->postJson('/api/v1/shipments', $this->payload(['bus_trip_id' => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors('bus_trip_id');
        $this->postJson('/api/v1/shipments', $this->payload())->assertCreated()->assertJsonPath('data.bus_trip_id', null);

        $this->assertSame(1, Shipment::count());
    }

    public function test_a_full_trip_refuses_more_express_shipments_and_leaves_nothing_behind(): void
    {
        $trip = PassengerTrip::factory()->create(['express_seats' => 1]);
        $this->express($trip);
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $this->postJson('/api/v1/shipments', $this->payload(['bus_trip_id' => $trip->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('bus_trip_id');

        $this->assertSame(1, Shipment::count());
        $this->assertSame(0, DB::table('shipment_items')->count());
    }

    public function test_a_trip_without_express_seats_closed_trips_and_trips_past_the_cutoff_refuse_express_shipments(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        foreach ([
            PassengerTrip::factory()->create(['express_seats' => 0]),
            PassengerTrip::factory()->create(['status' => 'departed']),
            PassengerTrip::factory()->create(['status' => 'express_locked']),
            PassengerTrip::factory()->create(['depart_at' => now()->subHour()]),
            // The scheduled command has not run yet, but the cutoff is already in the past.
            PassengerTrip::factory()->create(['express_cutoff_at' => now()->subMinute()]),
        ] as $closed) {
            $this->postJson('/api/v1/shipments', $this->payload(['bus_trip_id' => $closed->id]))
                ->assertUnprocessable()->assertJsonValidationErrors('bus_trip_id');
        }

        $this->assertSame(0, Shipment::count());
    }

    public function test_cancelling_an_express_shipment_frees_its_seat(): void
    {
        $trip = PassengerTrip::factory()->create(['express_seats' => 1]);
        $shipment = $this->express($trip);
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $this->getJson("/api/v1/trips/{$trip->id}")->assertJsonPath('data.express_free', 0);

        $this->postJson("/api/v1/shipments/{$shipment->id}/cancel", ['reason' => 'العميل غير رأيه'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.bus_trip_id', null);

        $this->getJson("/api/v1/trips/{$trip->id}")->assertJsonPath('data.express_used', 0)->assertJsonPath('data.express_free', 1);
        $this->postJson('/api/v1/shipments', $this->payload(['bus_trip_id' => $trip->id]))->assertCreated();
    }

    public function test_editing_a_draft_moves_it_to_another_trip_or_unlinks_it(): void
    {
        $first = PassengerTrip::factory()->create(['express_seats' => 1]);
        $second = PassengerTrip::factory()->create(['express_seats' => 1]);
        $full = PassengerTrip::factory()->create(['express_seats' => 1]);
        $this->express($full);
        $shipment = $this->express($first);
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        // Saving the form again with its own trip is not "a second shipment on a full trip".
        $this->putJson("/api/v1/shipments/{$shipment->id}", ['bus_trip_id' => $first->id, 'notes' => 'x'])->assertOk();
        $this->putJson("/api/v1/shipments/{$shipment->id}", ['bus_trip_id' => $full->id])
            ->assertUnprocessable()->assertJsonValidationErrors('bus_trip_id');
        $this->putJson("/api/v1/shipments/{$shipment->id}", ['bus_trip_id' => $second->id])->assertOk()
            ->assertJsonPath('data.bus_trip_id', $second->id);
        $this->assertSame(0, $first->expressUsed());

        $this->putJson("/api/v1/shipments/{$shipment->id}", ['bus_trip_id' => null])->assertOk()->assertJsonPath('data.bus_trip_id', null);
        $this->assertSame(0, $second->expressUsed());

        $this->putJson("/api/v1/shipments/{$shipment->id}", ['bus_trip_id' => $first->id])->assertOk();
        $this->putJson("/api/v1/shipments/{$shipment->id}", ['type' => 'normal'])->assertOk()->assertJsonPath('data.bus_trip_id', null);
        $this->assertSame(0, $first->expressUsed());

        $this->putJson("/api/v1/shipments/{$shipment->id}", ['type' => 'normal', 'bus_trip_id' => $first->id])
            ->assertUnprocessable()->assertJsonValidationErrors('bus_trip_id');
    }

    public function test_after_the_cutoff_unused_express_seats_are_sold_to_passengers(): void
    {
        $trip = PassengerTrip::factory()->create(['passenger_seats' => 48, 'express_seats' => 4, 'express_cutoff_at' => now()->addHour()]);
        $this->express($trip);
        $this->express($trip, ['status' => 'cancelled', 'bus_trip_id' => null]);
        $treasury = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'cash']);
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $book = fn (int $seat) => $this->postJson('/api/v1/bookings', [
            'trip_id' => $trip->id, 'seat_no' => $seat, 'passenger_name' => 'سعد', 'treasury_id' => $treasury->id,
        ]);

        $book(49)->assertUnprocessable()->assertJsonValidationErrors('seat_no');
        $this->getJson("/api/v1/trips/{$trip->id}/seats")->assertJsonCount(48, 'data.seats');

        $this->travel(2)->hours();
        $this->artisan('gomana:lock-express-trips')->assertSuccessful();
        $this->assertSame('express_locked', $trip->fresh()->status);

        // 4 express seats, 1 used by a live shipment: 3 are released.
        $this->getJson("/api/v1/trips/{$trip->id}")
            ->assertJsonPath('data.express_locked', true)
            ->assertJsonPath('data.express_used', 1)
            ->assertJsonPath('data.express_free', 0)
            ->assertJsonPath('data.express_released', 3)
            ->assertJsonPath('data.sellable_seats', 51)
            ->assertJsonPath('data.free_seats', 51);
        $this->getJson("/api/v1/trips/{$trip->id}/seats")->assertJsonCount(51, 'data.seats');

        $book(51)->assertCreated()->assertJsonPath('data.seat_no', 51);
        $book(52)->assertUnprocessable()->assertJsonValidationErrors('seat_no');
        $this->getJson("/api/v1/trips/{$trip->id}")->assertJsonPath('data.free_seats', 50);
    }

    public function test_the_lock_command_skips_trips_whose_cutoff_has_not_passed_and_is_idempotent(): void
    {
        $later = PassengerTrip::factory()->create(['express_cutoff_at' => now()->addDay()]);
        $due = PassengerTrip::factory()->create(['express_cutoff_at' => now()->subMinute()]);

        $this->artisan('gomana:lock-express-trips')->expectsOutput('1 trip(s) locked.')->assertSuccessful();
        $this->artisan('gomana:lock-express-trips')->expectsOutput('0 trip(s) locked.')->assertSuccessful();

        $this->assertSame('open', $later->fresh()->status);
        $this->assertSame('express_locked', $due->fresh()->status);
    }

    public function test_express_seats_cannot_be_cut_below_the_linked_shipments_and_such_a_trip_cannot_be_cancelled(): void
    {
        $trip = PassengerTrip::factory()->create(['express_seats' => 3]);
        $this->express($trip);
        $this->express($trip);
        $this->signInAs('faisal_staff');

        $this->putJson("/api/v1/trips/{$trip->id}", ['express_seats' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('express_seats');
        $this->putJson("/api/v1/trips/{$trip->id}", ['express_seats' => 2])->assertOk()->assertJsonPath('data.express_free', 0);
        $this->postJson("/api/v1/trips/{$trip->id}/cancel")->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_released_seats_count_when_checking_booked_seats_against_a_trip_edit(): void
    {
        $trip = PassengerTrip::factory()->create(['passenger_seats' => 48, 'express_seats' => 4, 'status' => 'express_locked']);
        Booking::factory()->create(['trip_id' => $trip->id, 'seat_no' => 50]);
        $this->signInAs('faisal_staff');

        $this->putJson("/api/v1/trips/{$trip->id}", ['passenger_seats' => 40])
            ->assertUnprocessable()->assertJsonValidationErrors('passenger_seats');
        $this->putJson("/api/v1/trips/{$trip->id}", ['price' => '2600'])->assertOk();
    }

    public function test_the_trip_file_lists_only_the_express_shipments_the_user_may_see(): void
    {
        $trip = PassengerTrip::factory()->create(['express_seats' => 3]);
        $mine = $this->express($trip);
        $this->express($trip, ['branch_id' => Branch::factory()->create()->id]);
        $this->express($trip, ['status' => 'cancelled', 'bus_trip_id' => null]);

        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $own = $this->getJson("/api/v1/trips/{$trip->id}/express-shipments")->assertOk();
        $this->assertSame([$mine->id], collect($own->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/trips/{$trip->id}")->assertJsonPath('data.express_used', 2);

        $this->signInAs('faisal_staff');
        $this->getJson("/api/v1/trips/{$trip->id}/express-shipments")->assertOk()->assertJsonCount(2, 'data');
    }
}
