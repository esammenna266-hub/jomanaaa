<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Http\Resources\PassengerTripResource;
use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Booking\Services\PassengerTripService;
use App\Support\AccessScope;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PassengerTripController extends Controller
{
    private const MONEY = '/^\d{1,12}(\.\d{1,2})?$/';

    public function __construct(private PassengerTripService $trips) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('trip.view');

        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(['bus', 'flight', 'ship'])],
            'status' => ['nullable', Rule::in(['open', 'express_locked', 'departed', 'completed', 'cancelled'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sellable' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $trips = PassengerTrip::query()
            ->with('vehicle:id,plate_no,seats', 'driver:id,name')
            ->withCount(['liveBookings', 'liveExpressShipments'])
            ->when($filters['kind'] ?? null, fn ($query, $value) => $query->where('kind', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->where('depart_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->where('depart_at', '<=', $value))
            ->when($request->boolean('sellable'), fn ($query) => $query->whereIn('status', PassengerTrip::SELLABLE)->where('depart_at', '>', now()))
            ->orderBy('depart_at')
            ->paginate($filters['per_page'] ?? 25);

        return PassengerTripResource::collection($trips);
    }

    public function show(int $id): PassengerTripResource
    {
        Gate::authorize('trip.view');

        return new PassengerTripResource(PassengerTrip::withCount(['liveBookings', 'liveExpressShipments'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('trip.manage');

        $trip = $this->trips->create($request->validate($this->rules(null)), $request->user()->id);

        return (new PassengerTripResource($trip))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): PassengerTripResource
    {
        Gate::authorize('trip.manage');

        $trip = PassengerTrip::findOrFail($id);

        return new PassengerTripResource($this->trips->update($trip, $request->validate($this->rules($trip))));
    }

    public function depart(int $id): PassengerTripResource
    {
        Gate::authorize('trip.manage');

        return new PassengerTripResource($this->trips->depart(PassengerTrip::findOrFail($id)));
    }

    public function complete(int $id): PassengerTripResource
    {
        Gate::authorize('trip.manage');

        return new PassengerTripResource($this->trips->complete(PassengerTrip::findOrFail($id)));
    }

    public function cancel(int $id): PassengerTripResource
    {
        Gate::authorize('trip.manage');

        return new PassengerTripResource($this->trips->cancel(PassengerTrip::findOrFail($id)));
    }

    /**
     * The seat map: every passenger seat, free or taken. Passenger details need booking.view; who sold the seat
     * is only shown to users who may see every booker (the owner).
     */
    public function seats(Request $request, int $id): JsonResponse
    {
        Gate::authorize('trip.view');

        $user = $request->user();
        $trip = PassengerTrip::withCount(['liveBookings', 'liveExpressShipments'])->findOrFail($id);
        $showPassengers = $user->hasPermission('booking.view');
        $showBooker = $user->scopeFor('booking.view_booker') === 'all';

        $bookings = $trip->liveBookings()->with('branch:id,name', 'agent:id,name')->get()->keyBy('seat_no');
        $paid = DB::table('payments')->whereIn('booking_id', $bookings->pluck('id'))->groupBy('booking_id')
            ->selectRaw("booking_id, SUM(CASE direction WHEN 'in' THEN amount ELSE -amount END) AS net")->pluck('net', 'booking_id');

        $seats = collect(range(1, $trip->sellableSeats()))->map(function (int $number) use ($bookings, $paid, $showPassengers, $showBooker): array {
            $booking = $bookings->get($number);
            if ($booking === null) {
                return ['no' => $number, 'status' => 'free'];
            }

            return [
                'no' => $number,
                'status' => 'booked',
                ...($showPassengers ? ['booking' => [
                    'id' => $booking->id,
                    'serial_no' => $booking->serial_no,
                    'passenger_name' => $booking->passenger_name,
                    'passport_no' => $booking->passport_no,
                    'destination' => $booking->destination,
                    'status' => $booking->status,
                    'sale_price' => $booking->sale_price,
                    'paid' => (string) BigDecimal::of($paid[$booking->id] ?? 0)->toScale(2),
                ]] : []),
                ...($showBooker ? ['booked_by' => ['branch' => $booking->branch?->name, 'agent' => $booking->agent?->name]] : []),
            ];
        })->values();

        return response()->json(['data' => [
            'trip' => new PassengerTripResource($trip),
            'seats' => $seats,
        ]]);
    }

    /**
     * Passenger list in seat order: the data behind the manifest.
     */
    public function passengers(int $id): JsonResponse
    {
        Gate::authorize('trip.manage');

        $trip = PassengerTrip::findOrFail($id);
        $passengers = $trip->liveBookings()->orderBy('seat_no')->get(['id', 'serial_no', 'seat_no', 'passenger_name', 'passport_no', 'destination', 'status']);

        return response()->json(['data' => $passengers]);
    }

    /**
     * Express shipments riding this trip, limited to the ones the user may see; the seat count in the trip
     * resource covers all of them.
     */
    public function expressShipments(Request $request, int $id): JsonResponse
    {
        Gate::authorize('trip.view');
        Gate::authorize('shipment.view');

        $trip = PassengerTrip::findOrFail($id);
        $shipments = AccessScope::apply($trip->liveExpressShipments()->getQuery(), $request->user(), 'shipment.view')
            ->with('customer:id,name', 'destination:id,name', 'branch:id,name')
            ->orderBy('id')
            ->get(['id', 'serial_no', 'tracking_code', 'status', 'branch_id', 'customer_id', 'destination_id', 'consignee_name', 'consignee_phone', 'currency_id', 'price', 'discount']);

        return response()->json(['data' => $shipments]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?PassengerTrip $trip): array
    {
        $required = $trip === null ? 'required' : 'sometimes';

        return [
            'kind' => [$required, Rule::in(['bus', 'flight', 'ship'])],
            'route' => [$required, 'string', 'max:150'],
            'depart_at' => [$required, 'date', ...($trip === null ? ['after:now'] : [])],
            'checkin_at' => ['nullable', 'date', 'before_or_equal:depart_at'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'passenger_seats' => [$required, 'integer', 'min:1', 'max:1000'],
            'express_seats' => ['sometimes', 'integer', 'min:0', 'max:200'],
            'express_cutoff_at' => ['nullable', 'date', 'before_or_equal:depart_at'],
            'price' => [$required, 'regex:'.self::MONEY],
            'currency_id' => [$required, 'integer', 'exists:currencies,id'],
        ];
    }
}
