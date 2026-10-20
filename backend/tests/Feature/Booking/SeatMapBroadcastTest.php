<?php

namespace Tests\Feature\Booking;

use App\Modules\Booking\Events\SeatMapChanged;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Identity\Models\User;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Live seat maps: every change to a trip's seats is announced on that trip's private channel, without data, and a
 * dead socket server never stops a booking.
 */
class SeatMapBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Branch $branch;

    private Treasury $cash;

    private PassengerTrip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->cash = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'cash']);
        $this->trip = PassengerTrip::factory()->create(['express_seats' => 2]);
    }

    private function staff(): User
    {
        return $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
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
            'treasury_id' => $this->cash->id,
            ...$overrides,
        ]);
    }

    private function announcedTrips(): array
    {
        return Event::dispatched(SeatMapChanged::class)->map(fn (array $call): int => $call[0]->tripId)->values()->all();
    }

    public function test_booking_a_seat_announces_the_trip_once_with_no_passenger_data(): void
    {
        $this->staff();
        Event::fake([SeatMapChanged::class]);

        $this->book()->assertCreated();

        $this->assertSame([$this->trip->id], $this->announcedTrips());
        $event = new SeatMapChanged($this->trip->id);
        $this->assertSame(['trip_id' => $this->trip->id], $event->broadcastWith());
        $this->assertSame('seats.changed', $event->broadcastAs());
        $this->assertSame('private-passenger-trip.'.$this->trip->id, $event->broadcastOn()[0]->name);
    }

    public function test_cancelling_a_booking_and_moving_the_trip_along_are_announced(): void
    {
        $this->staff();
        $id = $this->book()->json('data.id');

        Event::fake([SeatMapChanged::class]);
        $this->postJson("/api/v1/bookings/{$id}/cancel", ['reason' => 'x'])->assertOk();
        $this->assertSame([$this->trip->id], $this->announcedTrips());

        Event::fake([SeatMapChanged::class]);
        $this->signInAs('owner');
        $this->postJson("/api/v1/trips/{$this->trip->id}/depart")->assertOk();
        $this->assertSame([$this->trip->id], $this->announcedTrips());
    }

    public function test_a_failed_booking_announces_nothing(): void
    {
        $this->staff();
        $this->book()->assertCreated();

        Event::fake([SeatMapChanged::class]);
        $this->book(['passenger_name' => 'غيره'])->assertUnprocessable();

        Event::assertNotDispatched(SeatMapChanged::class);
    }

    public function test_express_shipments_joining_moving_or_leaving_a_trip_announce_the_trips_involved(): void
    {
        $other = PassengerTrip::factory()->create(['express_seats' => 2]);
        $shipment = Shipment::factory()->create(['branch_id' => $this->branch->id, 'type' => 'express', 'status' => 'approved']);

        Event::fake([SeatMapChanged::class]);
        $shipment->update(['bus_trip_id' => $this->trip->id]);
        $this->assertSame([$this->trip->id], $this->announcedTrips());

        Event::fake([SeatMapChanged::class]);
        $shipment->update(['bus_trip_id' => $other->id]);
        $this->assertEqualsCanonicalizing([$other->id, $this->trip->id], $this->announcedTrips());

        Event::fake([SeatMapChanged::class]);
        $shipment->update(['status' => 'cancelled', 'bus_trip_id' => null]);
        $this->assertSame([$other->id], $this->announcedTrips());
    }

    public function test_a_shipment_without_a_bus_trip_announces_nothing(): void
    {
        Event::fake([SeatMapChanged::class]);

        Shipment::factory()->create(['branch_id' => $this->branch->id])->update(['status' => 'approved']);

        Event::assertNotDispatched(SeatMapChanged::class);
    }

    public function test_a_dead_socket_server_never_stops_a_booking(): void
    {
        $this->staff();
        Event::listen(SeatMapChanged::class, fn () => throw new RuntimeException('socket server is down'));

        $this->book()->assertCreated();

        $this->assertSame(1, Booking::count());
    }

    public function test_listeners_must_prove_who_they_are_with_the_api_token(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        // The channels were registered on the test driver when the app booted; register them on the one in use.
        Broadcast::purge();
        require base_path('routes/channels.php');
        $payload = ['socket_id' => '1234.5678', 'channel_name' => 'private-passenger-trip.'.$this->trip->id];

        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertUnauthorized();

        $this->staff();
        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_a_stopped_account_cannot_listen(): void
    {
        $user = User::factory()->withRole('branch_staff')->create(['branch_id' => $this->branch->id, 'active' => false]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-passenger-trip.1'])->assertUnauthorized();
    }
}
