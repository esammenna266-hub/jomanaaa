<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Identity\Models\User;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShipmentService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers customer payments against a shipment (AC-01, AC-04, AC-05).
 */
final class CollectionService
{
    public function __construct(private LedgerService $ledger, private ShipmentService $shipments) {}

    public function collect(
        User $user,
        Shipment $shipment,
        Treasury $treasury,
        string $amount,
        ?int $installmentId = null,
        ?string $notes = null,
    ): Payment {
        return DB::transaction(function () use ($user, $shipment, $treasury, $amount, $installmentId, $notes): Payment {
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, Shipment::COLLECTABLE, true)) {
                throw ValidationException::withMessages(['shipment_id' => 'مينفعش تحصّل على الشحنة في حالتها دي']);
            }

            if (! $treasury->active) {
                throw ValidationException::withMessages(['treasury_id' => 'الخزنة موقوفة']);
            }

            if ($user->scopeFor('payment.collect') === 'branch' && $treasury->branch_id !== $user->branch_id) {
                throw ValidationException::withMessages(['treasury_id' => 'الخزنة دي مش تابعة لفرعك']);
            }

            $sameCurrency = $treasury->currency_id === $locked->currency_id;
            if ($sameCurrency && BigDecimal::of($amount)->isGreaterThan($this->shipments->balance($locked)['remaining'])) {
                throw ValidationException::withMessages(['amount' => 'المبلغ أكبر من المتبقي على الشحنة']);
            }

            $installment = null;
            if ($installmentId !== null) {
                $installment = Installment::where('shipment_id', $locked->id)->lockForUpdate()->find($installmentId);

                if ($installment === null || ! in_array($installment->status, ['due', 'overdue'], true)) {
                    throw ValidationException::withMessages(['installment_id' => 'القسط مش متاح للدفع']);
                }

                if (! $sameCurrency || BigDecimal::of($amount)->isLessThan($installment->amount)) {
                    throw ValidationException::withMessages(['amount' => 'المبلغ لازم يغطي القسط بنفس عملته']);
                }
            }

            $payment = $this->ledger->post($treasury, 'in', 'collection', $amount, [
                'payer_type' => 'customer',
                'payer_id' => $locked->customer_id,
                'shipment_id' => $locked->id,
                'installment_id' => $installment?->id,
                'notes' => $notes,
            ]);

            $installment?->update(['status' => 'paid']);

            return $payment;
        });
    }
}
