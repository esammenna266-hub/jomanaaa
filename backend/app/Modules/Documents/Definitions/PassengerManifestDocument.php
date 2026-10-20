<?php

namespace App\Modules\Documents\Definitions;

use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Identity\Models\User;
use App\Support\SerialNumberService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * مانيفست الركاب: issued once the trip has left, so the archived copy is the final passenger list.
 */
final class PassengerManifestDocument extends DocumentDefinition
{
    public function __construct(private SerialNumberService $serials) {}

    public function type(): string
    {
        return 'passenger_manifest';
    }

    public function paper(): string
    {
        return 'A4';
    }

    public function view(): string
    {
        return 'documents.passenger-manifest';
    }

    public function title(): string
    {
        return 'مانيفست الركاب';
    }

    public function subjectType(): string
    {
        return 'passenger_trip';
    }

    public function find(int $subjectId): Model
    {
        return PassengerTrip::findOrFail($subjectId);
    }

    public function resolve(int $subjectId, User $user): Model
    {
        Gate::authorize('trip.manage');

        $trip = PassengerTrip::findOrFail($subjectId);

        if (! in_array($trip->status, ['departed', 'completed'], true)) {
            throw ValidationException::withMessages(['subject_id' => 'المانيفست بيتطلع بعد ما الرحلة تتحرك']);
        }

        return $trip;
    }

    public function serial(Model $subject): string
    {
        return $this->serials->next('passenger_manifest');
    }

    public function data(Model $subject): array
    {
        $subject->load('vehicle', 'driver');

        return [
            'trip' => $subject,
            'passengers' => $subject->liveBookings()->orderBy('seat_no')->get(),
            'kinds' => ['bus' => 'أتوبيس', 'flight' => 'طيران', 'ship' => 'باخرة'],
        ];
    }
}
