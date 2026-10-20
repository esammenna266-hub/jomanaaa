<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\BorderPoint;
use App\Modules\MasterData\Models\ClearingAgent;
use App\Modules\MasterData\Models\Destination;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ShipmentOptionsController extends Controller
{
    /**
     * The dropdown lists the shipment form needs, for anyone allowed to create shipments
     * (external offices cannot browse master data, but they do need these choices).
     */
    public function __invoke(): JsonResponse
    {
        Gate::authorize('shipment.create');

        $active = fn (string $model) => $model::where('active', true)->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => [
            'border_points' => $active(BorderPoint::class),
            'destinations' => $active(Destination::class),
            'clearing_agents' => $active(ClearingAgent::class),
        ]]);
    }
}
