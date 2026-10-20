<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Http\Resources\ServiceBookingResource;
use App\Modules\Booking\Models\ServiceBooking;
use App\Modules\Booking\Services\ServiceBookingService;
use App\Modules\MasterData\Models\Customer;
use App\Support\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hotel and car bookings. Same permissions and data scope as bus bookings.
 */
class ServiceBookingController extends Controller
{
    private const MONEY = '/^\d{1,12}(\.\d{1,2})?$/';

    public function __construct(private ServiceBookingService $bookings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('booking.view');

        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['hotel', 'car'])],
            'status' => ['nullable', Rule::in(['reserved', 'paid', 'completed', 'cancelled'])],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $bookings = AccessScope::apply(ServiceBooking::query(), $request->user(), 'booking.view')
            ->with('customer:id,name,phone')
            ->when($filters['type'] ?? null, fn ($query, $value) => $query->where('type', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(fn ($inner) => $inner
                ->where('serial_no', 'like', "%{$term}%")
                ->orWhere('details', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ServiceBookingResource::collection($bookings);
    }

    public function show(Request $request, int $id): ServiceBookingResource
    {
        return new ServiceBookingResource($this->find($request, 'booking.view', $id));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('booking.create');

        $data = $request->validate([
            'type' => ['required', Rule::in(['hotel', 'car'])],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'details' => ['required', 'string', 'max:2000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'price' => ['required', 'regex:'.self::MONEY, 'gt:0'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'treasury_id' => ['nullable', 'integer', 'exists:treasuries,id'],
        ]);

        $user = $request->user();

        if (! AccessScope::apply(Customer::query(), $user, 'customer.view')->whereKey($data['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'العميل مش متاح ليك']);
        }

        return (new ServiceBookingResource($this->bookings->create($user, $data)))->response()->setStatusCode(201);
    }

    public function collect(Request $request, int $id): ServiceBookingResource
    {
        Gate::authorize('booking.create');

        $data = $request->validate(['treasury_id' => ['required', 'integer', 'exists:treasuries,id']]);

        // Offices settle with the company on their account; only company staff take money at the counter.
        if ($request->user()->agent_id !== null) {
            abort(403);
        }

        return new ServiceBookingResource($this->bookings->collect($request->user(), $this->find($request, 'booking.create', $id), $data['treasury_id']));
    }

    public function complete(Request $request, int $id): ServiceBookingResource
    {
        Gate::authorize('booking.create');

        return new ServiceBookingResource($this->bookings->complete($this->find($request, 'booking.create', $id)));
    }

    public function cancel(Request $request, int $id): ServiceBookingResource
    {
        Gate::authorize('booking.cancel_refund');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new ServiceBookingResource($this->bookings->cancel($this->find($request, 'booking.cancel_refund', $id), $data['reason']));
    }

    private function find(Request $request, string $permission, int $id): ServiceBooking
    {
        Gate::authorize($permission);

        return AccessScope::apply(ServiceBooking::query(), $request->user(), $permission)->findOrFail($id);
    }
}
