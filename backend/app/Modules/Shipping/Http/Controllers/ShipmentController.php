<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Modules\Finance\Services\InstallmentService;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Shipping\Http\Resources\ShipmentResource;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShipmentService;
use App\Support\AccessScope;
use App\Support\PhoneNumber;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShipmentController extends Controller
{
    private const MONEY = '/^\d{1,12}(\.\d{1,2})?$/';

    public function __construct(private ShipmentService $shipments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $shipments = $this->visible($request, 'shipment.view')
            ->with('customer:id,name,phone')
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['branch_id'] ?? null, fn ($query, $value) => $query->where('branch_id', $value))
            ->when($filters['customer_id'] ?? null, fn ($query, $value) => $query->where('customer_id', $value))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(function ($inner) use ($term): void {
                $inner->where('serial_no', 'like', "%{$term}%")
                    ->orWhere('tracking_code', $term)
                    ->orWhere('consignee_name', 'like', "%{$term}%")
                    ->orWhere('consignee_phone', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
            }))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ModelResource::collection($shipments);
    }

    public function show(Request $request, int $id): ShipmentResource
    {
        return new ShipmentResource($this->visible($request, 'shipment.view')->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('shipment.create');

        $validated = $request->validate($this->rules(null));
        $items = $validated['items'];
        $data = $this->headerData($request, $validated);

        $data['agent_id'] = $request->user()->agent_id;
        $data['branch_id'] = $this->shipments->branchFor($request->user(), $validated['branch_id'] ?? null);

        if ($data['branch_id'] === null) {
            throw ValidationException::withMessages(['branch_id' => 'حدد الفرع']);
        }

        $this->guardCustomerScope($request, $data['customer_id']);

        return (new ShipmentResource($this->shipments->create($request->user(), $data, $items)))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): ShipmentResource
    {
        Gate::authorize('shipment.create');

        $shipment = $this->visible($request, 'shipment.create')->findOrFail($id);
        $validated = $request->validate($this->rules($shipment));
        $data = $this->headerData($request, $validated, $shipment);

        if (isset($data['customer_id'])) {
            $this->guardCustomerScope($request, $data['customer_id']);
        }

        return new ShipmentResource($this->shipments->update($shipment, $data, $validated['items'] ?? null));
    }

    public function submit(Request $request, int $id): ShipmentResource
    {
        Gate::authorize('shipment.create');

        $shipment = $this->visible($request, 'shipment.create')->findOrFail($id);

        return new ShipmentResource($this->shipments->submit($shipment, $request->user()));
    }

    public function receive(Request $request, int $id): ShipmentResource
    {
        Gate::authorize('shipment.receive');

        $shipment = $this->visible($request, 'shipment.receive')->findOrFail($id);

        return new ShipmentResource($this->shipments->receive($shipment));
    }

    /**
     * Shipments waiting at the destination, found by tracking code, serial or consignee. Only what delivery needs.
     */
    public function arrived(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:3', 'max:100']]);
        $term = $data['q'];

        $shipments = $this->visible($request, 'shipment.deliver')
            ->where('status', 'arrived')
            ->where(fn ($query) => $query->where('tracking_code', strtoupper($term))
                ->orWhere('serial_no', $term)
                ->orWhere('consignee_phone', 'like', "%{$term}%")
                ->orWhere('consignee_name', 'like', "%{$term}%"))
            ->with('customer:id,name', 'destination:id,name')
            ->orderBy('id')
            ->limit(25)
            ->get();

        return response()->json(['data' => $shipments->map(fn (Shipment $shipment) => [
            'id' => $shipment->id,
            'serial_no' => $shipment->serial_no,
            'tracking_code' => $shipment->tracking_code,
            'consignee_name' => $shipment->consignee_name,
            'consignee_phone' => $shipment->consignee_phone,
            'customer' => $shipment->customer?->name,
            'destination' => $shipment->destination?->name,
            'balance' => $this->shipments->balance($shipment),
        ])]);
    }

    public function deliver(Request $request, int $id): ShipmentResource
    {
        Gate::authorize('shipment.deliver');

        $data = $request->validate(['receiver_name' => ['required', 'string', 'max:150']]);
        $shipment = $this->visible($request, 'shipment.deliver')->findOrFail($id);

        return new ShipmentResource($this->shipments->deliver($shipment, $data['receiver_name']));
    }

    public function cancel(Request $request, int $id): ShipmentResource
    {
        Gate::authorize('shipment.cancel');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $shipment = $this->visible($request, 'shipment.cancel')->findOrFail($id);

        return new ShipmentResource($this->shipments->cancel($shipment, $data['reason']));
    }

    public function scheduleInstallments(Request $request, InstallmentService $installments, int $id): ShipmentResource
    {
        Gate::authorize('payment.collect');

        $data = $request->validate([
            'installments' => ['required', 'array', 'min:1', 'max:36'],
            'installments.*.due_date' => ['required', 'date', 'after_or_equal:today'],
            'installments.*.amount' => ['required', 'regex:'.self::MONEY, 'gt:0'],
        ]);

        $shipment = $this->visible($request, 'payment.collect')->findOrFail($id);
        $installments->schedule($shipment, $data['installments']);

        return new ShipmentResource($shipment->refresh());
    }

    private function visible(Request $request, string $permission): Builder
    {
        Gate::authorize($permission);

        return AccessScope::apply(Shipment::query(), $request->user(), $permission);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Shipment $shipment): array
    {
        $required = $shipment === null ? 'required' : 'sometimes';
        $money = ['nullable', 'regex:'.self::MONEY];

        return [
            'customer_id' => [$required, 'integer', Rule::exists('customers', 'id')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'type' => ['sometimes', Rule::in(['normal', 'express'])],
            'bus_trip_id' => ['nullable', 'integer', Rule::exists('trips', 'id')],
            'consignee_name' => [$required, 'string', 'max:150'],
            'consignee_phone' => [$required, 'string', 'max:20'],
            'border_point_id' => ['nullable', 'integer', Rule::exists('border_points', 'id')],
            'destination_id' => [$required, 'integer', Rule::exists('destinations', 'id')],
            'clearing_agent_id' => ['nullable', 'integer', Rule::exists('clearing_agents', 'id')],
            'proposed_category' => ['nullable', Rule::in(['jumbo', 'single', 'trailer', 'bus'])],
            'proposed_body' => ['nullable', Rule::in(['closed', 'open'])],
            'proposed_tons' => ['nullable', 'numeric', 'min:0'],
            'expected_date' => ['nullable', 'date'],
            'currency_id' => [$required, 'integer', Rule::exists('currencies', 'id')],
            'price' => $money,
            'discount' => $money,
            'agent_sale_price' => $money,
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => [$required, 'array', 'min:1', 'max:200'],
            'items.*.name' => ['required', 'string', 'max:150'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['required', Rule::in(['piece', 'carton', 'sack', 'barrel', 'pack'])],
            'items.*.per_pack' => ['nullable', 'numeric', 'gt:0'],
            'items.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function headerData(Request $request, array $validated, ?Shipment $current = null): array
    {
        $data = collect($validated)->except(['items', 'branch_id'])->all();

        if (isset($data['consignee_phone'])) {
            try {
                $data['consignee_phone'] = PhoneNumber::normalize($data['consignee_phone'], 'SD');
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['consignee_phone' => 'رقم تليفون المستلم غير صحيح']);
            }
        }

        // Only external offices set a customer sale price; for everyone else it stays empty.
        if ($request->user()->agent_id === null) {
            unset($data['agent_sale_price']);
        }

        $price = $data['price'] ?? $current?->price ?? '0';
        $discount = $data['discount'] ?? $current?->discount ?? '0';
        if (BigDecimal::of($discount)->isGreaterThan($price)) {
            throw ValidationException::withMessages(['discount' => 'الخصم أكبر من السعر']);
        }

        foreach (['price', 'discount'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                $data[$key] = '0';
            }
        }

        return $data;
    }

    private function guardCustomerScope(Request $request, int $customerId): void
    {
        $visible = AccessScope::apply(Customer::query(), $request->user(), 'customer.view')->whereKey($customerId)->exists();

        if (! $visible) {
            throw ValidationException::withMessages(['customer_id' => 'العميل مش متاح ليك']);
        }
    }
}
