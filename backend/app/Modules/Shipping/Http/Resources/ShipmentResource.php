<?php

namespace App\Modules\Shipping\Http\Resources;

use App\Modules\Shipping\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full shipment file: header, items, ledger rows, installments and the computed balance.
 */
class ShipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $shipment = $this->resource->loadMissing(
            'customer:id,name,phone',
            'branch:id,name',
            'destination:id,name',
            'busTrip:id,kind,route,depart_at,status',
            'borderPoint:id,name',
            'clearingAgent:id,name',
            'items',
            'installments',
            'payments',
            'approvals',
        );

        // Company trip expenses are internal: external offices never see them.
        if ($request->user()?->agent_id === null) {
            $shipment->loadMissing([
                'expenseAllocations' => fn ($query) => $query->whereHas('expense', fn ($expense) => $expense->whereNull('voided_at')),
                'expenseAllocations.expense:id,category,point,currency_id',
            ]);
        }

        return [
            ...$shipment->toArray(),
            'balance' => app(ShipmentService::class)->balance($shipment),
        ];
    }
}
