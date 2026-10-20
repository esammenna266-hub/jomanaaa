<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Treasury;
use App\Support\SerialNumberService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way money moves. Rows are appended to `payments`; nothing is edited or deleted,
 * and mistakes are corrected with a reversal row.
 */
final class LedgerService
{
    public function __construct(private SerialNumberService $serials) {}

    /**
     * @param  array<string, mixed>  $context  payer_type, payer_id, shipment_id, installment_id, notes, reversal_of_id
     */
    public function post(Treasury $treasury, string $direction, string $kind, string $amount, array $context = []): Payment
    {
        return DB::transaction(fn (): Payment => Payment::create([
            ...$context,
            'serial_no' => $this->serials->next('payment'),
            'direction' => $direction,
            'kind' => $kind,
            'treasury_id' => $treasury->id,
            'amount' => $amount,
            'currency_id' => $treasury->currency_id,
            'created_by' => auth()->id(),
        ]));
    }

    public function reverse(Payment $payment, string $kind = 'reversal', ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($payment, $kind, $notes): Payment {
            $original = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($original->reversal_of_id !== null) {
                throw ValidationException::withMessages(['payment' => 'مينفعش تعكس حركة عكسية']);
            }

            if (Payment::where('reversal_of_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'الحركة دي اتعكست قبل كده']);
            }

            $reversal = $this->post(
                Treasury::findOrFail($original->treasury_id),
                $original->direction === 'in' ? 'out' : 'in',
                $kind,
                (string) $original->amount,
                [
                    'payer_type' => $original->payer_type,
                    'payer_id' => $original->payer_id,
                    'shipment_id' => $original->shipment_id,
                    'booking_id' => $original->booking_id,
                    'service_booking_id' => $original->service_booking_id,
                    'installment_id' => $original->installment_id,
                    'reversal_of_id' => $original->id,
                    'notes' => $notes,
                ],
            );

            if ($original->installment_id !== null) {
                Installment::whereKey($original->installment_id)->update(['status' => 'due']);
            }

            return $reversal;
        });
    }

    /**
     * Net money received for a shipment in one currency (collections minus refunds and reversals).
     */
    public function netCollected(int $shipmentId, int $currencyId): BigDecimal
    {
        return $this->netByCurrency($shipmentId)[$currencyId] ?? BigDecimal::zero()->toScale(2);
    }

    /**
     * @return array<int, BigDecimal> currency_id => net amount
     */
    public function netByCurrency(int $shipmentId): array
    {
        return DB::table('payments')
            ->where('shipment_id', $shipmentId)
            ->groupBy('currency_id')
            ->selectRaw("currency_id, SUM(CASE direction WHEN 'in' THEN amount ELSE -amount END) AS net")
            ->pluck('net', 'currency_id')
            ->map(fn ($net): BigDecimal => BigDecimal::of($net)->toScale(2))
            ->all();
    }
}
