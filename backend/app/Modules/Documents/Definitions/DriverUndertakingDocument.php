<?php

namespace App\Modules\Documents\Definitions;

use App\Modules\Identity\Models\User;
use App\Modules\Trips\Models\CargoTrip;
use App\Modules\Trips\Models\DriverUndertaking;
use Illuminate\Database\Eloquent\Model;

/**
 * تعهد السائق: the driver's delivery commitment with this trip's fingerprint.
 */
final class DriverUndertakingDocument extends DocumentDefinition
{
    public function type(): string
    {
        return 'driver_undertaking';
    }

    public function paper(): string
    {
        return 'A4';
    }

    public function view(): string
    {
        return 'documents.driver-undertaking';
    }

    public function title(): string
    {
        return 'تعهد سائق';
    }

    public function subjectType(): string
    {
        return 'driver_undertaking';
    }

    public function find(int $subjectId): Model
    {
        return DriverUndertaking::findOrFail($subjectId);
    }

    public function resolve(int $subjectId, User $user): Model
    {
        $this->authorizeAny('cargo_trip.manage', 'tracking.update');

        return DriverUndertaking::findOrFail($subjectId);
    }

    public function serial(Model $subject): string
    {
        return $subject->serial_no;
    }

    public function data(Model $subject): array
    {
        $trip = CargoTrip::with('vehicle', 'driver', 'borderPoint')->findOrFail($subject->cargo_trip_id);

        return [
            'undertaking' => $subject,
            'trip' => $trip,
            'driver' => $trip->driver,
            'currency' => $subject->currency_id === null ? null : $this->currencyName($subject->currency_id),
            'fingerprint' => $this->imageDataUri($subject->fingerprint_path),
            'idTypes' => ['national_id' => 'بطاقة رقم قومي', 'passport' => 'جواز سفر', 'other' => 'أخرى'],
            'text' => config('gomana.undertaking_text'),
        ];
    }
}
