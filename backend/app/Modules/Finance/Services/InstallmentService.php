<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Installment;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShipmentService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InstallmentService
{
    public function __construct(private ShipmentService $shipments) {}

    /**
     * Schedules part of the unpaid balance (AC-03). Open installments plus the new ones can never exceed it.
     *
     * @param  list<array{due_date: string, amount: string}>  $rows
     * @return list<Installment>
     */
    public function schedule(Shipment $shipment, array $rows): array
    {
        return DB::transaction(function () use ($shipment, $rows): array {
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, Shipment::COLLECTABLE, true)) {
                throw ValidationException::withMessages(['status' => 'الأقساط بتتجدول بعد اعتماد السعر']);
            }

            $open = BigDecimal::of(
                (string) Installment::where('shipment_id', $locked->id)->whereIn('status', ['due', 'overdue'])->sum('amount'),
            );
            $available = BigDecimal::of($this->shipments->balance($locked)['remaining'])->minus($open);
            $requested = array_reduce($rows, fn (BigDecimal $sum, array $row) => $sum->plus($row['amount']), BigDecimal::zero());

            if ($requested->isGreaterThan($available)) {
                throw ValidationException::withMessages([
                    'installments' => "مجموع الأقساط أكبر من المتبقي غير المجدول ({$available->toScale(2)})",
                ]);
            }

            return array_map(fn (array $row): Installment => Installment::create([
                'shipment_id' => $locked->id,
                'due_date' => $row['due_date'],
                'amount' => $row['amount'],
                'currency_id' => $locked->currency_id,
                'created_by' => auth()->id(),
            ]), $rows);
        });
    }
}
