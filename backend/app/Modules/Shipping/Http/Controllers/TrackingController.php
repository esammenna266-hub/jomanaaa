<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Trips\Models\TripStageEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Public tracking page API: current status and dates only. No names, phones or money (SH-10, NF security).
 */
class TrackingController extends Controller
{
    public function show(string $code): JsonResponse
    {
        $shipment = Shipment::with('destination:id,name')->where('tracking_code', strtoupper($code))->firstOrFail();

        $statusHistory = DB::table('audit_logs')
            ->where(['entity' => 'shipments', 'entity_id' => (string) $shipment->id])
            ->whereNotNull('after_json')
            ->orderBy('id')
            ->get(['action', 'after_json', 'at'])
            ->map(fn ($log) => ['status' => json_decode($log->after_json, true)['status'] ?? null, 'at' => $log->at])
            ->filter(fn (array $entry) => $entry['status'] !== null)
            ->values();

        $stages = $shipment->cargo_trip_id === null ? collect() : TripStageEvent::where(['trip_type' => 'cargo', 'trip_id' => $shipment->cargo_trip_id])
            ->orderBy('happened_at')->orderBy('id')->get(['stage', 'happened_at']);

        return response()->json(['data' => [
            'tracking_code' => $shipment->tracking_code,
            'status' => $shipment->status,
            'destination' => $shipment->destination?->name,
            'expected_date' => $shipment->expected_date?->toDateString(),
            'status_history' => $statusHistory,
            'trip_stages' => $stages,
        ]]);
    }
}
