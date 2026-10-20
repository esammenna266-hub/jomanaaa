<?php

namespace App\Modules\Trips\Services;

use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Trips\Models\CargoTrip;
use App\Modules\Trips\Models\TripExpense;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money spent on a truck is paid from a treasury and split across the shipments it carries (EX-01, EX-02).
 */
final class ExpenseService
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * @param  array<string, string>|null  $manual  shipment_id => amount; null splits evenly
     */
    public function record(
        User $user,
        CargoTrip $trip,
        Treasury $treasury,
        string $category,
        ?string $point,
        string $amount,
        ?array $manual,
    ): TripExpense {
        return DB::transaction(function () use ($user, $trip, $treasury, $category, $point, $amount, $manual): TripExpense {
            $locked = CargoTrip::whereKey($trip->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage === 'cancelled') {
                throw ValidationException::withMessages(['trip' => 'الرحلة دي اتلغت']);
            }

            if (! $treasury->active) {
                throw ValidationException::withMessages(['treasury_id' => 'الخزنة موقوفة']);
            }

            $shipmentIds = Shipment::where('cargo_trip_id', $locked->id)->orderBy('id')->pluck('id')->all();

            if ($shipmentIds === []) {
                throw ValidationException::withMessages(['trip' => 'مفيش شحنات على الرحلة دي توزع عليها']);
            }

            $allocations = $manual === null ? $this->splitEvenly($amount, $shipmentIds) : $this->checkManual($amount, $shipmentIds, $manual);

            $payment = $this->ledger->post($treasury, 'out', 'expense', $amount, [
                'payer_type' => 'other',
                'notes' => "مصروف رحلة {$locked->serial_no}: {$category}",
            ]);

            $expense = TripExpense::create([
                'cargo_trip_id' => $locked->id,
                'category' => $category,
                'point' => $point,
                'amount' => $amount,
                'currency_id' => $treasury->currency_id,
                'treasury_id' => $treasury->id,
                'payment_id' => $payment->id,
                'created_by' => $user->id,
            ]);

            foreach ($allocations as $shipmentId => $share) {
                $expense->allocations()->create(['shipment_id' => $shipmentId, 'amount' => $share]);
            }

            return $expense;
        });
    }

    /**
     * Voids a mistaken expense: the payment is reversed and the expense stays on record, marked as void.
     */
    public function void(User $user, TripExpense $expense, string $reason): TripExpense
    {
        return DB::transaction(function () use ($user, $expense, $reason): TripExpense {
            $locked = TripExpense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($locked->voided_at !== null) {
                throw ValidationException::withMessages(['expense' => 'المصروف ده اتلغى قبل كده']);
            }

            $this->ledger->reverse($locked->payment()->firstOrFail(), 'reversal', "إلغاء مصروف: {$reason}");
            $locked->update(['voided_at' => now(), 'voided_by' => $user->id]);

            return $locked;
        });
    }

    /**
     * @param  list<int>  $shipmentIds
     * @return array<int, string>
     */
    private function splitEvenly(string $amount, array $shipmentIds): array
    {
        $cents = BigDecimal::of($amount)->withPointMovedRight(2)->toInt();
        $count = count($shipmentIds);
        $base = intdiv($cents, $count);
        $extra = $cents % $count;

        $shares = [];
        foreach ($shipmentIds as $index => $id) {
            $shares[$id] = (string) BigDecimal::of($base + ($index < $extra ? 1 : 0))->withPointMovedLeft(2)->toScale(2);
        }

        return $shares;
    }

    /**
     * @param  list<int>  $shipmentIds
     * @param  array<string|int, string>  $manual
     * @return array<int, string>
     */
    private function checkManual(string $amount, array $shipmentIds, array $manual): array
    {
        $shares = [];
        $sum = BigDecimal::zero();

        foreach ($manual as $shipmentId => $share) {
            if (! in_array((int) $shipmentId, $shipmentIds, true)) {
                throw ValidationException::withMessages(['allocations' => "الشحنة رقم {$shipmentId} مش على الرحلة دي"]);
            }

            $shares[(int) $shipmentId] = (string) BigDecimal::of($share)->toScale(2);
            $sum = $sum->plus($share);
        }

        if (! $sum->isEqualTo($amount)) {
            throw ValidationException::withMessages([
                'allocations' => 'مجموع التوزيع ('.$sum->toScale(2).') لازم يساوي المصروف ('.BigDecimal::of($amount)->toScale(2).')',
            ]);
        }

        return $shares;
    }
}
