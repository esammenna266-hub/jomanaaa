<?php

namespace App\Modules\Trips\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Trips\Http\Resources\CargoTripResource;
use App\Modules\Trips\Models\CargoTrip;
use App\Modules\Trips\Services\CargoTripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CargoTripController extends Controller
{
    public function __construct(private CargoTripService $trips) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeRead();

        $filters = $request->validate([
            'stage' => ['nullable', Rule::in([...CargoTrip::STAGES, 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $trips = CargoTrip::query()
            ->with('vehicle:id,plate_no', 'driver:id,name')
            ->withCount('shipments')
            ->when($filters['stage'] ?? null, fn ($query, $value) => $query->where('stage', $value))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return CargoTripResource::collection($trips);
    }

    public function show(int $id): CargoTripResource
    {
        $this->authorizeRead();

        return new CargoTripResource(CargoTrip::findOrFail($id));
    }

    /**
     * Multipart request: trip data, the undertaking terms, the shipments to load and the fingerprint image.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('cargo_trip.manage');

        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'border_point_id' => ['nullable', 'integer', 'exists:border_points,id'],
            'clearing_agent_id' => ['nullable', 'integer', 'exists:clearing_agents,id'],
            'shipment_ids' => ['required', 'array', 'min:1', 'max:200'],
            'shipment_ids.*' => ['integer', 'distinct'],
            'fingerprint' => $this->fingerprintRules(),
            'pickup_point' => ['nullable', 'string', 'max:150'],
            'delivery_point' => ['nullable', 'string', 'max:150'],
            'delivery_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'daily_penalty' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'currency_id' => ['nullable', 'required_with:daily_penalty', 'integer', 'exists:currencies,id'],
        ]);

        $trip = $this->trips->load(
            $request->user(),
            collect($data)->only(['vehicle_id', 'driver_id', 'border_point_id', 'clearing_agent_id'])->all(),
            collect($data)->only(['pickup_point', 'delivery_point', 'delivery_days', 'daily_penalty', 'currency_id'])->all(),
            $data['shipment_ids'],
            $request->file('fingerprint'),
        );

        return (new CargoTripResource($trip->refresh()))->response()->setStatusCode(201);
    }

    public function replaceFingerprint(Request $request, int $id): CargoTripResource
    {
        Gate::authorize('cargo_trip.manage');

        $request->validate(['fingerprint' => $this->fingerprintRules()]);
        $trip = CargoTrip::findOrFail($id);
        $this->trips->replaceFingerprint($trip, $request->file('fingerprint'));

        return new CargoTripResource($trip->refresh()->load('undertaking'));
    }

    public function stage(Request $request, int $id): CargoTripResource
    {
        Gate::authorize('tracking.update');

        $data = $request->validate([
            'stage' => ['required', Rule::in(CargoTrip::STAGES)],
            'happened_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $trip = $this->trips->advance(
            $request->user(),
            CargoTrip::findOrFail($id),
            $data['stage'],
            isset($data['happened_at']) ? Carbon::parse($data['happened_at']) : null,
            $data['notes'] ?? null,
        );

        return new CargoTripResource($trip->refresh());
    }

    public function cancel(Request $request, int $id): CargoTripResource
    {
        Gate::authorize('cargo_trip.manage');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new CargoTripResource($this->trips->cancel($request->user(), CargoTrip::findOrFail($id), $data['reason'])->refresh());
    }

    /**
     * Manifest data (المانيفست): every shipment on the truck with totals per unit. Prices are never included.
     */
    public function manifest(int $id): JsonResponse
    {
        $this->authorizeRead();

        $trip = $this->loadForDocuments($id);
        $lines = $trip->shipments->flatMap->items;
        $weight = $lines->sum(fn ($item) => (float) $item->weight_kg);
        $maxTon = $trip->vehicle->max_ton;

        return response()->json(['data' => [
            ...$this->header($trip),
            'shipments' => $trip->shipments->map(fn ($shipment) => $this->shipmentLine($shipment))->values(),
            'totals' => [
                'shipments' => $trip->shipments->count(),
                'by_unit' => $lines->groupBy('unit')->map(fn ($group) => number_format($group->sum(fn ($item) => (float) $item->qty), 2, '.', '')),
                'weight_kg' => number_format($weight, 2, '.', ''),
            ],
            'over_capacity' => $maxTon === null || $weight == 0.0 ? null : $weight > ((float) $maxTon * 1000),
        ]]);
    }

    /**
     * Waybill data (البوليصة): one sheet per shipment, numbered "X of Y", carrying the driver's fingerprint link.
     */
    public function waybills(int $id): JsonResponse
    {
        $this->authorizeRead();

        $trip = $this->loadForDocuments($id);
        $total = $trip->shipments->count();
        $fingerprint = $trip->undertaking?->fingerprint_path === null ? null : URL::temporarySignedRoute(
            'cargo-trips.fingerprint', now()->addMinutes(10), ['id' => $trip->id],
        );

        return response()->json(['data' => $trip->shipments->values()->map(fn ($shipment, int $index) => [
            ...$this->header($trip),
            'sheet' => ['number' => $index + 1, 'of' => $total],
            'shipment' => $this->shipmentLine($shipment),
            'fingerprint_url' => $fingerprint,
        ])]);
    }

    /**
     * Signed, short-lived link to the stored fingerprint image.
     */
    public function fingerprint(int $id): StreamedResponse
    {
        $path = CargoTrip::findOrFail($id)->undertaking?->fingerprint_path;
        abort_if($path === null || ! Storage::exists($path), 404);

        return Storage::response($path);
    }

    private function loadForDocuments(int $id): CargoTrip
    {
        return CargoTrip::with(
            'vehicle:id,plate_no,category,body,max_ton',
            'driver:id,name,phone,license_no',
            'borderPoint:id,name',
            'clearingAgent:id,name,phone',
            'undertaking',
            'shipments.items',
            'shipments.customer:id,name,phone',
            'shipments.destination:id,name',
        )->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(CargoTrip $trip): array
    {
        return [
            'trip' => $trip->only(['serial_no', 'stage', 'departed_at']),
            'vehicle' => $trip->vehicle,
            'driver' => $trip->driver,
            'border_point' => $trip->borderPoint?->name,
            'clearing_agent' => $trip->clearingAgent,
            'undertaking' => $trip->undertaking?->only(['serial_no', 'pickup_point', 'delivery_point', 'delivery_days', 'daily_penalty', 'currency_id', 'signed_at']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentLine(mixed $shipment): array
    {
        return [
            'serial_no' => $shipment->serial_no,
            'tracking_code' => $shipment->tracking_code,
            'customer' => $shipment->customer?->only(['name', 'phone']),
            'consignee_name' => $shipment->consignee_name,
            'consignee_phone' => $shipment->consignee_phone,
            'destination' => $shipment->destination?->name,
            'items' => $shipment->items->map->only(['name', 'qty', 'unit', 'per_pack', 'weight_kg', 'notes'])->values(),
        ];
    }

    private function authorizeRead(): void
    {
        abort_unless(Gate::any(['cargo_trip.manage', 'tracking.update']), 403);
    }

    /**
     * @return list<mixed>
     */
    private function fingerprintRules(): array
    {
        return ['required', 'file', 'mimes:png,jpg,jpeg,bmp', 'max:2048'];
    }
}
