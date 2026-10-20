<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Core\Models\AgentPrice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Support\SerialNumberService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seat sales. The trip row is locked for the whole transaction, so two employees booking the same seat at the
 * same moment are served one after the other: the second one is told the seat is taken (NF-02). The unique
 * index on live bookings stays as a second line of defence.
 */
final class BookingService
{
    public function __construct(private SerialNumberService $serials, private LedgerService $ledger) {}

    /**
     * Counter staff pay in full when they book. External offices book on their own account (status "reserved")
     * and settle with the company later.
     *
     * @param  array{trip_id: int, seat_no: int, passenger_name: string, passport_no?: string|null, destination?: string|null, customer_id?: int|null, sale_price?: string|null, treasury_id?: int|null}  $data
     */
    public function book(User $user, array $data): Booking
    {
        try {
            return DB::transaction(fn (): Booking => $this->createLocked($user, $data));
        } catch (UniqueConstraintViolationException) {
            // The unique index on live bookings caught a clash the lock did not (e.g. a manual insert).
            throw ValidationException::withMessages(['seat_no' => 'الكرسي اتحجز قبل كده']);
        }
    }

    /**
     * Frees the seat and refunds everything collected for it, one reversal per original payment.
     */
    public function cancel(Booking $booking, string $reason): Booking
    {
        return DB::transaction(function () use ($booking, $reason): Booking {
            // Same lock order as book(): trip first, then the booking.
            $trip = PassengerTrip::whereKey($booking->trip_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['reserved', 'paid'], true)) {
                throw ValidationException::withMessages(['status' => 'الحجز ده ملغي أو اتسافر']);
            }

            if (in_array($trip->status, ['departed', 'completed'], true)) {
                throw ValidationException::withMessages(['status' => 'الرحلة اتحركت ومينفعش تلغي الحجز']);
            }

            Payment::where('booking_id', $locked->id)
                ->where('direction', 'in')
                ->where('kind', 'collection')
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('payments as r')->whereColumn('r.reversal_of_id', 'payments.id'))
                ->get()
                ->each(fn (Payment $payment) => $this->ledger->reverse($payment, 'refund', $reason));

            $locked->update(['status' => 'cancelled', 'cancel_reason' => $reason]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createLocked(User $user, array $data): Booking
    {
        $trip = PassengerTrip::whereKey($data['trip_id'])->lockForUpdate()->firstOrFail();

        if (! in_array($trip->status, PassengerTrip::SELLABLE, true) || $trip->depart_at->isPast()) {
            throw ValidationException::withMessages(['trip_id' => 'الحجز على الرحلة دي اتقفل']);
        }

        // Unused express seats join the passenger seats once the express cutoff has passed (BS-09).
        $sellable = $trip->sellableSeats();
        if ($data['seat_no'] < 1 || $data['seat_no'] > $sellable) {
            throw ValidationException::withMessages(['seat_no' => "رقم الكرسي لازم يكون من 1 لـ {$sellable}"]);
        }

        if ($trip->liveBookings()->where('seat_no', $data['seat_no'])->exists()) {
            throw ValidationException::withMessages(['seat_no' => 'الكرسي اتحجز قبل كده']);
        }

        $isOffice = $user->agent_id !== null;
        $treasury = $isOffice ? null : $this->payingTreasury($user, $trip, $data['treasury_id'] ?? null);
        $price = (string) $trip->price;
        $agentCost = $isOffice ? $this->agentCost($user->agent_id, $trip) : null;

        $booking = Booking::create([
            'serial_no' => $this->serials->next('booking'),
            'trip_id' => $trip->id,
            'seat_no' => $data['seat_no'],
            'passenger_name' => $data['passenger_name'],
            'passport_no' => $data['passport_no'] ?? null,
            'destination' => $data['destination'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'branch_id' => $user->branch_id,
            'agent_id' => $user->agent_id,
            'agent_price' => $agentCost,
            'sale_price' => $isOffice ? ($data['sale_price'] ?? $price) : $price,
            'currency_id' => $trip->currency_id,
            'status' => $isOffice ? 'reserved' : 'paid',
            'created_by' => $user->id,
        ]);

        if ($treasury !== null) {
            $this->ledger->post($treasury, 'in', 'collection', $price, [
                'payer_type' => $booking->customer_id === null ? 'other' : 'customer',
                'payer_id' => $booking->customer_id,
                'booking_id' => $booking->id,
                'notes' => "حجز {$booking->serial_no}",
            ]);
        }

        return $booking->refresh();
    }

    /**
     * What the office pays the company for this seat: its own price for the trip's kind and route when one is set
     * (AG-03), otherwise the trip's price.
     */
    private function agentCost(int $agentId, PassengerTrip $trip): string
    {
        $listed = AgentPrice::where('agent_id', $agentId)
            ->where('service', $trip->kind)
            ->where('route', $trip->route)
            ->where('currency_id', $trip->currency_id)
            ->where('active', true)
            ->value('price');

        return (string) ($listed ?? $trip->price);
    }

    private function payingTreasury(User $user, PassengerTrip $trip, ?int $treasuryId): Treasury
    {
        if ($treasuryId === null) {
            throw ValidationException::withMessages(['treasury_id' => 'اختار الخزنة اللي هتستلم فيها الفلوس']);
        }

        $treasury = Treasury::findOrFail($treasuryId);

        if (! $treasury->active) {
            throw ValidationException::withMessages(['treasury_id' => 'الخزنة موقوفة']);
        }

        if ($user->branch_id !== null && $treasury->branch_id !== $user->branch_id) {
            throw ValidationException::withMessages(['treasury_id' => 'الخزنة دي مش تابعة لفرعك']);
        }

        if ($treasury->currency_id !== $trip->currency_id) {
            throw ValidationException::withMessages(['treasury_id' => 'عملة الخزنة لازم تكون عملة الرحلة']);
        }

        return $treasury;
    }
}
