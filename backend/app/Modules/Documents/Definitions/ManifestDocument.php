<?php

namespace App\Modules\Documents\Definitions;

use App\Modules\Identity\Models\User;
use App\Modules\Trips\Models\CargoTrip;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * مانيفستو البضاعة: everything on the truck with totals per unit, numbered with the trip's serial.
 */
final class ManifestDocument extends DocumentDefinition
{
    public function type(): string
    {
        return 'manifest';
    }

    public function paper(): string
    {
        return 'A4';
    }

    public function view(): string
    {
        return 'documents.manifest';
    }

    public function title(): string
    {
        return 'مانيفستو البضاعة';
    }

    public function subjectType(): string
    {
        return 'cargo_trip';
    }

    public function find(int $subjectId): Model
    {
        return CargoTrip::findOrFail($subjectId);
    }

    public function resolve(int $subjectId, User $user): Model
    {
        $this->authorizeAny('cargo_trip.manage', 'tracking.update');

        $trip = CargoTrip::findOrFail($subjectId);

        if ($trip->stage === 'cancelled') {
            throw ValidationException::withMessages(['subject_id' => 'الرحلة دي اتلغت']);
        }

        return $trip;
    }

    public function serial(Model $subject): string
    {
        return $subject->serial_no;
    }

    public function data(Model $subject): array
    {
        $subject->load('vehicle', 'driver', 'borderPoint', 'clearingAgent', 'shipments.items', 'shipments.customer', 'shipments.destination');
        $lines = $subject->shipments->flatMap->items;

        return [
            'trip' => $subject,
            'totalsByUnit' => $lines->groupBy('unit')->map(fn ($group) => $group->sum(fn ($item) => (float) $item->qty)),
            'totalWeight' => $lines->sum(fn ($item) => (float) $item->weight_kg),
            'units' => ['piece' => 'قطعة', 'carton' => 'كرتونة', 'sack' => 'شوال', 'barrel' => 'برميل', 'pack' => 'عبوة'],
        ];
    }
}
