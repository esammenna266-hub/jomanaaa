<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Http\Resources\TransferResource;
use App\Modules\Shipping\Models\Transfer;
use App\Modules\Shipping\Services\TransferService;
use App\Support\AccessScope;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    public function __construct(private TransferService $transfers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,sent,received,received_with_diff'],
            'from_branch_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $transfers = $this->visible($request)
            ->with('fromBranch:id,name', 'toBranch:id,name')
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['from_branch_id'] ?? null, fn ($query, $value) => $query->where('from_branch_id', $value))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return TransferResource::collection($transfers);
    }

    public function show(Request $request, int $id): TransferResource
    {
        return new TransferResource($this->visible($request)->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('transfer.create');

        $data = $request->validate([
            'from_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'shipments' => ['required', 'array', 'min:1', 'max:200'],
            'shipments.*.shipment_id' => ['required', 'integer'],
            'shipments.*.sent_count' => ['nullable', 'numeric', 'gt:0'],
            'vehicle_text' => ['nullable', 'string', 'max:80'],
            'driver_text' => ['nullable', 'string', 'max:120'],
            'driver_phone' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();
        $fromBranchId = $user->scopeFor('transfer.create') === 'branch' ? $user->branch_id : ($data['from_branch_id'] ?? null);

        if ($fromBranchId === null) {
            throw ValidationException::withMessages(['from_branch_id' => 'حدد الفرع']);
        }

        $carrier = [
            'vehicle_text' => $data['vehicle_text'] ?? null,
            'driver_text' => $data['driver_text'] ?? null,
            'driver_phone' => isset($data['driver_phone']) ? $this->phone($data['driver_phone']) : null,
        ];

        $transfer = $this->transfers->send($user, $fromBranchId, $data['shipments'], $carrier);

        return (new TransferResource($transfer->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Resolves a scanned barcode (exit form serial or a shipment code) to the open transfer.
     */
    public function lookup(Request $request): TransferResource
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50']]);

        $transfer = $this->transfers->lookup($data['code']);
        abort_if($transfer === null, 404);

        return new TransferResource($this->visible($request)->findOrFail($transfer->id));
    }

    public function receive(Request $request, int $id): TransferResource
    {
        Gate::authorize('transfer.confirm_receipt');

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.shipment_id' => ['required', 'integer', 'distinct'],
            'items.*.received_count' => ['required', 'numeric', 'min:0'],
        ]);

        $transfer = $this->visible($request)->findOrFail($id);
        $counts = collect($data['items'])->pluck('received_count', 'shipment_id')->all();

        return new TransferResource($this->transfers->receive($request->user(), $transfer, $counts)->refresh());
    }

    /**
     * Senders see their own branch's forms; the receiving side (Faisal) and the owner see all.
     */
    private function visible(Request $request): Builder
    {
        $user = $request->user();

        if ($user->scopeFor('transfer.confirm_receipt') === 'all') {
            return Transfer::query();
        }

        Gate::authorize('transfer.create');

        return AccessScope::apply(Transfer::query(), $user, 'transfer.create', 'from_branch_id');
    }

    private function phone(string $raw): string
    {
        try {
            return PhoneNumber::normalize($raw);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['driver_phone' => 'رقم تليفون السواق غير صحيح']);
        }
    }
}
