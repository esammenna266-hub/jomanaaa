<?php

namespace App\Modules\Booking\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "Something changed on this trip": a seat was booked or freed, the trip moved on, an express shipment was linked.
 * It carries no data on purpose. The screens that hear it ask the API again, which applies the usual permissions, so
 * passenger names never travel over the socket.
 */
class SeatMapChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $tripId) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("passenger-trip.{$this->tripId}")];
    }

    public function broadcastAs(): string
    {
        return 'seats.changed';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['trip_id' => $this->tripId];
    }
}
