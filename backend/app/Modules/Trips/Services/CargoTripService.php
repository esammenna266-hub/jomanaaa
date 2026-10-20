<?php

namespace App\Modules\Trips\Services;

use App\Modules\Identity\Models\User;
use App\Modules\MasterData\Models\Driver;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Trips\Models\CargoTrip;
use App\Modules\Trips\Models\DriverUndertaking;
use App\Modules\Trips\Models\TripStageEvent;
use App\Support\SerialNumberService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Truck trips: loading shipments (with the driver's undertaking and fingerprint), stage updates, cancellation.
 */
final class CargoTripService
{
    public function __construct(private SerialNumberService $serials) {}

    /**
     * @param  array<string, mixed>  $trip  vehicle_id, driver_id, border_point_id, clearing_agent_id
     * @param  array<string, mixed>  $undertaking  pickup_point, delivery_point, delivery_days, daily_penalty, currency_id
     * @param  list<int>  $shipmentIds
     */
    public function load(User $user, array $trip, array $undertaking, array $shipmentIds, UploadedFile $fingerprint): CargoTrip
    {
        $path = null;

        try {
            return DB::transaction(function () use ($user, $trip, $undertaking, $shipmentIds, $fingerprint, &$path): CargoTrip {
                $vehicle = Vehicle::whereKey($trip['vehicle_id'])->lockForUpdate()->firstOrFail();
                $driver = Driver::whereKey($trip['driver_id'])->lockForUpdate()->firstOrFail();
                $this->guardAvailability($vehicle, $driver);

                $ids = array_values(array_unique($shipmentIds));
                $shipments = Shipment::whereIn('id', $ids)->lockForUpdate()->get();

                if ($shipments->count() !== count($ids)) {
                    throw ValidationException::withMessages(['shipment_ids' => 'فيه شحنة مش موجودة']);
                }

                foreach ($shipments as $shipment) {
                    if ($shipment->status !== 'at_main_warehouse' || $shipment->type !== 'normal' || $shipment->cargo_trip_id !== null) {
                        throw ValidationException::withMessages([
                            'shipment_ids' => "الشحنة {$shipment->serial_no} لازم تكون في مخزن فيصل وجاهزة للتحميل",
                        ]);
                    }
                }

                $cargoTrip = CargoTrip::create([
                    ...$trip,
                    'serial_no' => $this->serials->next('cargo_trip'),
                    'stage' => 'loaded',
                    'created_by' => $user->id,
                ]);

                $path = $fingerprint->store("fingerprints/trip-{$cargoTrip->id}");

                DriverUndertaking::create([
                    ...$undertaking,
                    'serial_no' => $this->serials->next('driver_undertaking'),
                    'cargo_trip_id' => $cargoTrip->id,
                    'driver_id' => $driver->id,
                    'fingerprint_path' => $path,
                    'signed_at' => now(),
                    'created_by' => $user->id,
                ]);

                foreach ($shipments as $shipment) {
                    $shipment->update(['status' => 'loaded', 'cargo_trip_id' => $cargoTrip->id]);
                }

                $this->recordEvent($cargoTrip, 'loaded', now(), $user, null);

                return $cargoTrip;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::delete($path);
            }

            throw $exception;
        }
    }

    /**
     * Moves the trip forward (never backward) and brings its shipments' statuses along.
     */
    public function advance(User $user, CargoTrip $trip, string $stage, ?Carbon $happenedAt, ?string $notes): CargoTrip
    {
        return DB::transaction(function () use ($user, $trip, $stage, $happenedAt, $notes): CargoTrip {
            $locked = CargoTrip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $current = array_search($locked->stage, CargoTrip::STAGES, true);
            $target = array_search($stage, CargoTrip::STAGES, true);

            if ($current === false) {
                throw ValidationException::withMessages(['stage' => 'الرحلة دي اتلغت']);
            }

            if ($target === false || $target <= $current) {
                throw ValidationException::withMessages(['stage' => 'المرحلة لازم تكون بعد المرحلة الحالية']);
            }

            if ($stage === 'completed' && $locked->stage !== 'arrived') {
                throw ValidationException::withMessages(['stage' => 'الرحلة لازم توصل الأول']);
            }

            $when = $happenedAt ?? now();
            $changes = ['stage' => $stage];
            if ($locked->departed_at === null && $target >= 1) {
                $changes['departed_at'] = $when;
            }
            if ($stage === 'arrived') {
                $changes['arrived_at'] = $when;
            }
            $locked->update($changes);

            if (isset(CargoTrip::SHIPMENT_STATUS[$stage])) {
                Shipment::where('cargo_trip_id', $locked->id)
                    ->whereIn('status', ['loaded', 'in_transit', 'arrived'])
                    ->get()
                    ->each(fn (Shipment $shipment) => $shipment->update(['status' => CargoTrip::SHIPMENT_STATUS[$stage]]));
            }

            $this->recordEvent($locked, $stage, $when, $user, $notes);

            return $locked;
        });
    }

    /**
     * Cancelling is only possible before the truck leaves; shipments go back to the warehouse.
     */
    public function cancel(User $user, CargoTrip $trip, string $reason): CargoTrip
    {
        return DB::transaction(function () use ($user, $trip, $reason): CargoTrip {
            $locked = CargoTrip::whereKey($trip->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage !== 'loaded') {
                throw ValidationException::withMessages(['stage' => 'الرحلة اتحركت ومينفعش تتلغي']);
            }

            Shipment::where('cargo_trip_id', $locked->id)->get()
                ->each(fn (Shipment $shipment) => $shipment->update(['status' => 'at_main_warehouse', 'cargo_trip_id' => null]));

            $locked->update(['stage' => 'cancelled']);
            $this->recordEvent($locked, 'cancelled', now(), $user, $reason);

            return $locked;
        });
    }

    /**
     * Replaces the captured fingerprint while the truck is still loading (FP-02).
     */
    public function replaceFingerprint(CargoTrip $trip, UploadedFile $fingerprint): void
    {
        if ($trip->stage !== 'loaded') {
            throw ValidationException::withMessages(['fingerprint' => 'البصمة بتتغير قبل تحرك الرحلة بس']);
        }

        $undertaking = $trip->undertaking()->firstOrFail();
        $old = $undertaking->fingerprint_path;

        $undertaking->update(['fingerprint_path' => $fingerprint->store("fingerprints/trip-{$trip->id}"), 'signed_at' => now()]);

        if ($old !== null) {
            Storage::delete($old);
        }
    }

    private function guardAvailability(Vehicle $vehicle, Driver $driver): void
    {
        if (! $vehicle->active || $vehicle->category === 'bus') {
            throw ValidationException::withMessages(['vehicle_id' => 'العربية مش متاحة للشحن']);
        }

        if (! $driver->active) {
            throw ValidationException::withMessages(['driver_id' => 'السواق موقوف']);
        }

        if ($driver->id_expiry_date !== null && $driver->id_expiry_date->copy()->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['driver_id' => 'هوية السواق منتهية']);
        }

        $busy = fn (string $column, int $id) => CargoTrip::where($column, $id)->whereNotIn('stage', ['completed', 'cancelled'])->exists();

        if ($busy('vehicle_id', $vehicle->id)) {
            throw ValidationException::withMessages(['vehicle_id' => 'العربية على رحلة شغالة']);
        }

        if ($busy('driver_id', $driver->id)) {
            throw ValidationException::withMessages(['driver_id' => 'السواق على رحلة شغالة']);
        }
    }

    private function recordEvent(CargoTrip $trip, string $stage, Carbon $when, User $user, ?string $notes): void
    {
        TripStageEvent::create([
            'trip_type' => 'cargo',
            'trip_id' => $trip->id,
            'stage' => $stage,
            'happened_at' => $when,
            'user_id' => $user->id,
            'notes' => $notes,
        ]);
    }
}
