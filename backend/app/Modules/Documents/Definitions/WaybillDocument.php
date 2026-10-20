<?php

namespace App\Modules\Documents\Definitions;

use App\Modules\Identity\Models\User;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Trips\Models\CargoTrip;
use App\Support\SerialNumberService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * بوليصة شحن: one sheet per shipment on a trip ("sheet X of Y"), with the driver's fingerprint and no prices.
 */
final class WaybillDocument extends DocumentDefinition
{
    public function __construct(private SerialNumberService $serials) {}

    public function type(): string
    {
        return 'waybill';
    }

    public function paper(): string
    {
        return 'A5';
    }

    public function view(): string
    {
        return 'documents.waybill';
    }

    public function title(): string
    {
        return 'بوليصة شحن / تخليص جمركي';
    }

    public function subjectType(): string
    {
        return 'shipment';
    }

    public function find(int $subjectId): Model
    {
        return Shipment::findOrFail($subjectId);
    }

    public function resolve(int $subjectId, User $user): Model
    {
        $this->authorizeAny('cargo_trip.manage', 'tracking.update');

        $shipment = Shipment::findOrFail($subjectId);

        if ($shipment->cargo_trip_id === null || $shipment->status === 'cancelled') {
            throw ValidationException::withMessages(['subject_id' => 'الشحنة لازم تكون محملة على رحلة']);
        }

        return $shipment;
    }

    public function serial(Model $subject): string
    {
        return $this->serials->next('waybill');
    }

    public function data(Model $subject): array
    {
        $subject->load('customer', 'items', 'destination');
        $trip = CargoTrip::with('vehicle', 'driver', 'borderPoint', 'clearingAgent', 'undertaking')->findOrFail($subject->cargo_trip_id);
        $siblings = Shipment::where('cargo_trip_id', $trip->id)->orderBy('id')->pluck('id');

        return [
            'shipment' => $subject,
            'trip' => $trip,
            'sheet' => ['number' => $siblings->search($subject->id) + 1, 'of' => $siblings->count()],
            'fingerprint' => $this->imageDataUri($trip->undertaking?->fingerprint_path),
        ];
    }
}
