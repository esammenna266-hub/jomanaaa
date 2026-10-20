<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\MasterData\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bus / flight / ship departures: creation, edits while sales are open, and the departure lifecycle.
 */
final class PassengerTripService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $userId): PassengerTrip
    {
        $capacity = $this->capacityFor($data);
        $this->guardSeats($capacity, (int) $data['passenger_seats'], (int) ($data['express_seats'] ?? 0));

        return PassengerTrip::create([...$data, 'capacity' => $capacity, 'status' => 'open', 'created_by' => $userId])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PassengerTrip $trip, array $data): PassengerTrip
    {
        return DB::transaction(function () use ($trip, $data): PassengerTrip {
            $locked = $this->lock($trip);
            $this->requireStatus($locked, PassengerTrip::SELLABLE, 'الرحلة اتحركت أو اتلغت ومينفعش تتعدل');

            $merged = [...$locked->getAttributes(), ...$data];
            $capacity = $this->capacityFor($merged);
            $passengerSeats = (int) $merged['passenger_seats'];
            $expressSeats = (int) $merged['express_seats'];
            $this->guardSeats($capacity, $passengerSeats, $expressSeats);

            $expressUsed = $locked->expressUsed();
            if ($expressSeats < $expressUsed) {
                throw ValidationException::withMessages(['express_seats' => "فيه {$expressUsed} شحنة سريعة مربوطة بالرحلة، مينفعش تقلل كراسي الشحن عن كده"]);
            }

            // Seats released from the express quota count as passenger seats, so the highest booked seat is
            // checked against the sellable total after the change.
            $locked->fill([...$data, 'capacity' => $capacity]);
            $highestSeat = (int) Booking::where('trip_id', $locked->id)->whereIn('status', PassengerTrip::LIVE_BOOKINGS)->max('seat_no');
            if ($locked->sellableSeats() < $highestSeat) {
                throw ValidationException::withMessages(['passenger_seats' => "فيه كرسي محجوز برقم {$highestSeat}، مينفعش تقلل العدد عنه"]);
            }

            $locked->save();

            return $locked->refresh();
        });
    }

    public function depart(PassengerTrip $trip): PassengerTrip
    {
        return $this->transition($trip, PassengerTrip::SELLABLE, 'departed', 'الرحلة اتحركت أو اتلغت قبل كده');
    }

    /**
     * Completing a trip marks everyone who held a seat as travelled.
     */
    public function complete(PassengerTrip $trip): PassengerTrip
    {
        return DB::transaction(function () use ($trip): PassengerTrip {
            $locked = $this->lock($trip);
            $this->requireStatus($locked, ['departed'], 'الرحلة لازم تتحرك الأول');

            Booking::where('trip_id', $locked->id)->whereIn('status', ['reserved', 'paid'])->get()
                ->each(fn (Booking $booking) => $booking->update(['status' => 'travelled']));
            $locked->update(['status' => 'completed']);

            return $locked->refresh();
        });
    }

    /**
     * A trip with live bookings cannot simply be cancelled: those bookings must be cancelled (and refunded) first.
     */
    public function cancel(PassengerTrip $trip): PassengerTrip
    {
        return DB::transaction(function () use ($trip): PassengerTrip {
            $locked = $this->lock($trip);
            $this->requireStatus($locked, PassengerTrip::SELLABLE, 'الرحلة اتحركت أو اتلغت قبل كده');

            if ($locked->liveBookings()->exists()) {
                throw ValidationException::withMessages(['status' => 'فيه حجوزات على الرحلة، الغيها واسترد فلوسها الأول']);
            }

            if ($locked->liveExpressShipments()->exists()) {
                throw ValidationException::withMessages(['status' => 'فيه شحنات سريعة مربوطة بالرحلة، الغيها أو انقلها لرحلة تانية الأول']);
            }

            $locked->update(['status' => 'cancelled']);

            return $locked->refresh();
        });
    }

    /**
     * Freezes the express (cargo) seats once the cutoff has passed (BS-09): no more shipments can be linked and
     * the seats nobody used are sold to passengers (see PassengerTrip::sellableSeats). Each trip is locked and
     * re-checked inside its own transaction, so a shipment being linked at that moment is either counted or refused.
     * Returns how many trips were locked.
     */
    public function lockExpiredExpress(): int
    {
        $count = 0;

        PassengerTrip::where('status', 'open')->whereNotNull('express_cutoff_at')->where('express_cutoff_at', '<=', now())->pluck('id')
            ->each(function (int $id) use (&$count): void {
                DB::transaction(function () use ($id, &$count): void {
                    $trip = PassengerTrip::whereKey($id)->lockForUpdate()->firstOrFail();

                    if ($trip->status === 'open' && $trip->express_cutoff_at !== null && $trip->express_cutoff_at->isPast()) {
                        $trip->update(['status' => 'express_locked']);
                        $count++;
                    }
                });
            });

        return $count;
    }

    /**
     * Locks the trip row and checks that one more express shipment can ride it: a bus trip that is still open
     * for express, before the cutoff, with an unused express seat. The row lock serialises two shipments taking
     * the last seat at once, the same way BookingService serialises two bookings of one seat. Must run inside
     * a transaction; the caller then saves the link while the lock is held.
     *
     * @param  int|null  $exceptShipmentId  a shipment already linked to this trip that is being edited
     */
    public function claimExpressSeat(int $tripId, ?int $exceptShipmentId = null): PassengerTrip
    {
        $trip = PassengerTrip::whereKey($tripId)->lockForUpdate()->firstOrFail();

        if ($trip->kind !== 'bus') {
            throw ValidationException::withMessages(['bus_trip_id' => 'الشحن السريع على رحلات الأتوبيس بس']);
        }

        if ($trip->status !== 'open' || $trip->depart_at->isPast() || $trip->isExpressLocked()) {
            throw ValidationException::withMessages(['bus_trip_id' => 'كراسي الشحن في الرحلة دي اتقفلت']);
        }

        $used = $trip->liveExpressShipments()->when($exceptShipmentId, fn ($query, $id) => $query->where('id', '<>', $id))->count();
        if ($used >= $trip->express_seats) {
            throw ValidationException::withMessages(['bus_trip_id' => (int) $trip->express_seats === 0
                ? 'الرحلة دي مفيهاش كراسي شحن'
                : "كراسي الشحن في الرحلة دي كملت ({$used} من {$trip->express_seats})"]);
        }

        return $trip;
    }

    /**
     * @param  list<string>  $from
     */
    private function transition(PassengerTrip $trip, array $from, string $to, string $message): PassengerTrip
    {
        return DB::transaction(function () use ($trip, $from, $to, $message): PassengerTrip {
            $locked = $this->lock($trip);
            $this->requireStatus($locked, $from, $message);
            $locked->update(['status' => $to]);

            return $locked->refresh();
        });
    }

    private function lock(PassengerTrip $trip): PassengerTrip
    {
        return PassengerTrip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<string>  $allowed
     */
    private function requireStatus(PassengerTrip $trip, array $allowed, string $message): void
    {
        if (! in_array($trip->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function capacityFor(array $data): int
    {
        $vehicleId = $data['vehicle_id'] ?? null;
        $vehicle = $vehicleId ? Vehicle::find($vehicleId) : null;

        if ($vehicle !== null && ($data['kind'] ?? 'bus') === 'bus' && $vehicle->category !== 'bus') {
            throw ValidationException::withMessages(['vehicle_id' => 'العربية دي مش أتوبيس']);
        }

        $capacity = $data['capacity'] ?? $vehicle?->seats;

        if ($capacity === null) {
            throw ValidationException::withMessages(['capacity' => 'حدد السعة أو اختار أتوبيس له عدد كراسي']);
        }

        return (int) $capacity;
    }

    private function guardSeats(int $capacity, int $passengerSeats, int $expressSeats): void
    {
        if ($passengerSeats + $expressSeats > $capacity) {
            throw ValidationException::withMessages(['passenger_seats' => "كراسي الركاب والشحن ({$passengerSeats} + {$expressSeats}) أكبر من السعة ({$capacity})"]);
        }
    }
}
