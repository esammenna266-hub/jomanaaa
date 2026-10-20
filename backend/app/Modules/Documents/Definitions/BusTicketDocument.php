<?php

namespace App\Modules\Documents\Definitions;

use App\Modules\Booking\Models\Booking;
use App\Modules\Identity\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * تذكرة أتوبيس + كعب: numbered with the booking's serial. It shows the price the passenger paid, never an office's cost.
 */
final class BusTicketDocument extends DocumentDefinition
{
    public function type(): string
    {
        return 'bus_ticket';
    }

    public function paper(): string
    {
        return 'TICKET';
    }

    public function view(): string
    {
        return 'documents.bus-ticket';
    }

    public function title(): string
    {
        return 'تذكرة أتوبيس';
    }

    public function subjectType(): string
    {
        return 'booking';
    }

    public function find(int $subjectId): Model
    {
        return Booking::findOrFail($subjectId);
    }

    public function resolve(int $subjectId, User $user): Model
    {
        Gate::authorize('booking.view');

        $booking = AccessScope::apply(Booking::query(), $user, 'booking.view')->findOrFail($subjectId);

        if ($booking->status === 'cancelled') {
            throw ValidationException::withMessages(['subject_id' => 'الحجز ملغي ومفيش تذكرة ليه']);
        }

        return $booking;
    }

    public function serial(Model $subject): string
    {
        return $subject->serial_no;
    }

    public function data(Model $subject): array
    {
        $subject->load('trip.vehicle');

        return [
            'booking' => $subject,
            'trip' => $subject->trip,
            'currency' => $this->currencyName($subject->currency_id),
        ];
    }
}
