<?php

namespace App\Modules\Shipping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exit form with its lines, the barcode value to print/scan, and the count-mismatch flag (WH-04).
 */
class TransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transfer = $this->resource->loadMissing(
            'fromBranch:id,name',
            'toBranch:id,name',
            'items.shipment:id,serial_no,tracking_code,consignee_name,customer_id',
            'items.shipment.customer:id,name',
        );

        return [
            ...$transfer->toArray(),
            'barcode' => $transfer->serial_no,
            'has_difference' => $transfer->status === 'received_with_diff',
        ];
    }
}
