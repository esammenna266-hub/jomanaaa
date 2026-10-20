<?php

namespace App\Modules\Booking\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PassengerTripResource extends JsonResource
{
    /**
     * Besides the row itself: how many seats are booked and still free for passengers (released express seats
     * included once the cutoff has passed) and how the express seats are used.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $trip = $this->resource->loadMissing('vehicle:id,plate_no,seats', 'driver:id,name,phone');
        $booked = $trip->live_bookings_count ?? $trip->liveBookings()->count();
        $sellable = $trip->sellableSeats();

        return [
            ...$trip->toArray(),
            'booked_seats' => $booked,
            'sellable_seats' => $sellable,
            'free_seats' => max($sellable - $booked, 0),
            'express_locked' => $trip->isExpressLocked(),
            'express_used' => $trip->expressUsed(),
            'express_free' => $trip->expressFree(),
            'express_released' => $trip->releasedExpressSeats(),
        ];
    }
}
