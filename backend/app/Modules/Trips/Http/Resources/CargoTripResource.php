<?php

namespace App\Modules\Trips\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Trip header with its truck, driver, undertaking, stage history and loaded shipments.
 */
class CargoTripResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $trip = $this->resource->loadMissing(
            'vehicle:id,plate_no,category,body,max_ton',
            'driver:id,name,phone',
            'borderPoint:id,name',
            'clearingAgent:id,name',
            'undertaking',
            'events',
            'shipments:id,serial_no,tracking_code,status,consignee_name,cargo_trip_id',
        );

        return [
            ...$trip->toArray(),
            'fingerprint_url' => $trip->undertaking?->fingerprint_path === null
                ? null
                : URL::temporarySignedRoute('cargo-trips.fingerprint', now()->addMinutes(10), ['id' => $trip->id]),
        ];
    }
}
