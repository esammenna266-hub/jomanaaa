<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\ServiceBooking;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Support\SerialNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hotel and car bookings. The full price is collected into a treasury when the booking is paid; an external
 * office books on account, and a counter booking without a treasury stays "reserved" until it is collected.
 */
final class ServiceBookingService
{
    public function __construct(private SerialNumberService $serials, private LedgerService $ledger) {}

    /**
     * @param  array{type: string, customer_id: int, details: string, start_date?: string|null, end_date?: string|null, price: string, currency_id: int, treasury_id?: int|null}  $data
     */
    public function create(User $user, array $data): ServiceBooking
    {
        $isOffice = $user->agent_id !== null;

        if ($isOffice && ! empty($data['treasury_id'])) {
            throw ValidationException::withMessages(['treasury_id' => 'المكتب الخارجي بيحجز على حسابه ومبيدفعش في خزنة']);
        }

        return DB::transaction(function () use ($user, $data, $isOffice): ServiceBooking {
            $booking = ServiceBooking::create([
                'serial_no' => $this->serials->next('service_booking'),
                'type' => $data['type'],
                'customer_id' => $data['customer_id'],
                'branch_id' => $user->branch_id,
                'agent_id' => $user->agent_id,
                'details' => $data['details'],
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'price' => $data['price'],
                'currency_id' => $data['currency_id'],
                'status' => 'reserved',
                'created_by' => $user->id,
            ]);

            if (! $isOffice && ! empty($data['treasury_id'])) {
                $this->pay($user, $booking, (int) $data['treasury_id']);
            }

            return $booking->refresh();
        });
    }

    /**
     * Collects the full price of a reserved booking.
     */
    public function collect(User $user, ServiceBooking $booking, int $treasuryId): ServiceBooking
    {
        return DB::transaction(function () use ($user, $booking, $treasuryId): ServiceBooking {
            $locked = ServiceBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'reserved') {
                throw ValidationException::withMessages(['status' => 'الحجز ده مش في انتظار الدفع']);
            }

            $this->pay($user, $locked, $treasuryId);

            return $locked->refresh();
        });
    }

    /**
     * Cancels the booking and refunds everything collected for it, one reversal per original payment.
     */
    public function cancel(ServiceBooking $booking, string $reason): ServiceBooking
    {
        return DB::transaction(function () use ($booking, $reason): ServiceBooking {
            $locked = ServiceBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['reserved', 'paid'], true)) {
                throw ValidationException::withMessages(['status' => 'الحجز ده ملغي أو خلص']);
            }

            Payment::where('service_booking_id', $locked->id)
                ->where('direction', 'in')
                ->where('kind', 'collection')
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('payments as r')->whereColumn('r.reversal_of_id', 'payments.id'))
                ->get()
                ->each(fn (Payment $payment) => $this->ledger->reverse($payment, 'refund', $reason));

            $locked->update(['status' => 'cancelled', 'cancel_reason' => $reason]);

            return $locked->refresh();
        });
    }

    public function complete(ServiceBooking $booking): ServiceBooking
    {
        return DB::transaction(function () use ($booking): ServiceBooking {
            $locked = ServiceBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'paid') {
                throw ValidationException::withMessages(['status' => 'الحجز لازم يتدفع الأول']);
            }

            $locked->update(['status' => 'completed']);

            return $locked->refresh();
        });
    }

    private function pay(User $user, ServiceBooking $booking, int $treasuryId): void
    {
        $treasury = Treasury::findOrFail($treasuryId);

        if (! $treasury->active) {
            throw ValidationException::withMessages(['treasury_id' => 'الخزنة موقوفة']);
        }

        if ($user->branch_id !== null && $treasury->branch_id !== $user->branch_id) {
            throw ValidationException::withMessages(['treasury_id' => 'الخزنة دي مش تابعة لفرعك']);
        }

        if ($treasury->currency_id !== $booking->currency_id) {
            throw ValidationException::withMessages(['treasury_id' => 'عملة الخزنة لازم تكون عملة الحجز']);
        }

        $this->ledger->post($treasury, 'in', 'collection', (string) $booking->price, [
            'payer_type' => 'customer',
            'payer_id' => $booking->customer_id,
            'service_booking_id' => $booking->id,
            'notes' => "حجز {$booking->serial_no}",
        ]);

        $booking->update(['status' => 'paid']);
    }
}
