<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Http\Resources\BookingResource;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingService;
use App\Modules\MasterData\Models\Customer;
use App\Support\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('booking.view');

        $filters = $request->validate([
            'trip_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['reserved', 'paid', 'travelled', 'cancelled'])],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $bookings = AccessScope::apply(Booking::query(), $request->user(), 'booking.view')
            ->with('trip:id,kind,route,depart_at,status')
            ->when($filters['trip_id'] ?? null, fn ($query, $value) => $query->where('trip_id', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(fn ($inner) => $inner
                ->where('passenger_name', 'like', "%{$term}%")
                ->orWhere('passport_no', 'like', "%{$term}%")
                ->orWhere('serial_no', 'like', "%{$term}%")))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return BookingResource::collection($bookings);
    }

    public function show(Request $request, int $id): BookingResource
    {
        Gate::authorize('booking.view');

        return new BookingResource(AccessScope::apply(Booking::query(), $request->user(), 'booking.view')->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('booking.create');

        $data = $request->validate([
            'trip_id' => ['required', 'integer', 'exists:trips,id'],
            'seat_no' => ['required', 'integer', 'min:1'],
            'passenger_name' => ['required', 'string', 'max:150'],
            'passport_no' => ['nullable', 'string', 'max:40'],
            'destination' => ['nullable', 'string', 'max:100'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'sale_price' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'treasury_id' => ['nullable', 'integer', 'exists:treasuries,id'],
        ]);

        $user = $request->user();

        if (isset($data['customer_id']) && ! AccessScope::apply(Customer::query(), $user, 'customer.view')->whereKey($data['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'العميل مش متاح ليك']);
        }

        // Only external offices set their own selling price; counter staff always sell at the trip price.
        if ($user->agent_id === null) {
            unset($data['sale_price']);
        }

        $booking = $this->bookings->book($user, $data);

        return (new BookingResource($booking))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, int $id): BookingResource
    {
        Gate::authorize('booking.cancel_refund');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $booking = AccessScope::apply(Booking::query(), $request->user(), 'booking.cancel_refund')->findOrFail($id);

        return new BookingResource($this->bookings->cancel($booking, $data['reason']));
    }
}
