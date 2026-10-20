<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Identity\Models\User;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\Transfer;
use App\Modules\Shipping\Models\TransferItem;
use App\Support\SerialNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves shipments from a branch to the main warehouse (WH-01..WH-04).
 * Sending flips shipments to "to_main_warehouse"; receiving compares counts and flips them to "at_main_warehouse".
 */
final class TransferService
{
    public function __construct(private SerialNumberService $serials) {}

    /**
     * @param  list<array{shipment_id: int, sent_count?: string|float|int|null}>  $lines
     * @param  array{vehicle_text?: string|null, driver_text?: string|null, driver_phone?: string|null}  $carrier
     */
    public function send(User $user, int $fromBranchId, array $lines, array $carrier): Transfer
    {
        $mainWarehouseId = Branch::where('is_main_warehouse', true)->value('id');

        if ($mainWarehouseId === null || $mainWarehouseId === $fromBranchId) {
            throw ValidationException::withMessages(['branch_id' => 'الفرع ده مينفعش يحوّل لنفسه']);
        }

        return DB::transaction(function () use ($user, $fromBranchId, $mainWarehouseId, $lines, $carrier): Transfer {
            $ids = array_column($lines, 'shipment_id');

            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['shipments' => 'فيه شحنة متكررة']);
            }

            $shipments = Shipment::whereIn('id', $ids)->lockForUpdate()->with('items')->get()->keyBy('id');

            foreach ($ids as $id) {
                $shipment = $shipments->get($id);

                if ($shipment === null || $shipment->branch_id !== $fromBranchId) {
                    throw ValidationException::withMessages(['shipments' => "الشحنة رقم {$id} مش في الفرع ده"]);
                }

                if ($shipment->status !== 'at_branch') {
                    throw ValidationException::withMessages(['shipments' => "الشحنة {$shipment->serial_no} لازم تكون مستلمة في الفرع"]);
                }
            }

            $transfer = Transfer::create([
                ...$carrier,
                'serial_no' => $this->serials->next('transfer_form'),
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $mainWarehouseId,
                'status' => 'sent',
                'sent_at' => now(),
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $shipment = $shipments->get($line['shipment_id']);

                $transfer->items()->create([
                    'shipment_id' => $shipment->id,
                    'sent_count' => $line['sent_count'] ?? $shipment->items->sum('qty'),
                ]);

                $shipment->update(['status' => 'to_main_warehouse']);
            }

            return $transfer;
        });
    }

    /**
     * Finds the open transfer behind a scanned barcode: the exit form's serial, or one of its shipments' codes.
     */
    public function lookup(string $code): ?Transfer
    {
        return Transfer::where('status', 'sent')
            ->where(function ($query) use ($code): void {
                $query->where('serial_no', $code)
                    ->orWhereHas('items.shipment', fn ($shipment) => $shipment
                        ->where('tracking_code', $code)->orWhere('serial_no', $code));
            })
            ->first();
    }

    /**
     * @param  array<int, string|float|int>  $counts  shipment_id => received count; every line must be answered
     */
    public function receive(User $user, Transfer $transfer, array $counts): Transfer
    {
        return DB::transaction(function () use ($user, $transfer, $counts): Transfer {
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'sent') {
                throw ValidationException::withMessages(['status' => 'الاستمارة دي اتستلمت قبل كده']);
            }

            $items = $locked->items()->with('shipment')->get();
            $expected = $items->pluck('shipment_id')->sort()->values()->all();
            $given = collect(array_keys($counts))->map(fn ($id) => (int) $id)->sort()->values()->all();

            if ($expected !== $given) {
                throw ValidationException::withMessages(['items' => 'لازم تدخل العدد المستلم لكل شحنة في الاستمارة']);
            }

            $hasDifference = false;

            $items->each(function (TransferItem $item) use ($counts, &$hasDifference): void {
                $received = $counts[$item->shipment_id];
                $item->update(['received_count' => $received]);

                $hasDifference = $hasDifference || (float) $received !== (float) $item->sent_count;

                if ((float) $received > 0) {
                    $item->shipment->update(['status' => 'at_main_warehouse']);
                }
            });

            $locked->update([
                'status' => $hasDifference ? 'received_with_diff' : 'received',
                'received_at' => now(),
                'received_by' => $user->id,
            ]);

            return $locked;
        });
    }
}
