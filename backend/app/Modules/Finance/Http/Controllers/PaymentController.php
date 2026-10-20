<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\CollectionService;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Shipping\Models\Shipment;
use App\Support\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    private const MONEY = '/^\d{1,12}(\.\d{1,2})?$/';

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('treasury.view');

        $filters = $request->validate([
            'treasury_id' => ['nullable', 'integer'],
            'shipment_id' => ['nullable', 'integer'],
            'direction' => ['nullable', 'in:in,out'],
            'kind' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $visibleTreasuries = AccessScope::apply(Treasury::query(), $request->user(), 'treasury.view')->select('treasuries.id');

        $payments = Payment::query()
            ->whereIn('treasury_id', $visibleTreasuries)
            ->when($filters['treasury_id'] ?? null, fn ($query, $value) => $query->where('treasury_id', $value))
            ->when($filters['shipment_id'] ?? null, fn ($query, $value) => $query->where('shipment_id', $value))
            ->when($filters['direction'] ?? null, fn ($query, $value) => $query->where('direction', $value))
            ->when($filters['kind'] ?? null, fn ($query, $value) => $query->where('kind', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->where('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->where('created_at', '<=', $value))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ModelResource::collection($payments);
    }

    public function store(Request $request, CollectionService $collections): JsonResponse
    {
        Gate::authorize('payment.collect');

        $data = $request->validate([
            'shipment_id' => ['required', 'integer'],
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'amount' => ['required', 'regex:'.self::MONEY, 'gt:0'],
            'installment_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $shipment = AccessScope::apply(Shipment::query(), $request->user(), 'payment.collect')
            ->findOrFail($data['shipment_id']);

        $payment = $collections->collect(
            $request->user(),
            $shipment,
            Treasury::findOrFail($data['treasury_id']),
            $data['amount'],
            $data['installment_id'] ?? null,
            $data['notes'] ?? null,
        );

        return (new ModelResource($payment->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Corrects a mistake by posting the opposite movement; the original row stays untouched.
     */
    public function reverse(Request $request, LedgerService $ledger, int $id): JsonResponse
    {
        Gate::authorize('payment.reverse');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $visibleTreasuries = AccessScope::apply(Treasury::query(), $request->user(), 'payment.reverse')->select('treasuries.id');
        $payment = Payment::whereIn('treasury_id', $visibleTreasuries)->findOrFail($id);

        if ($payment->remittance_id !== null) {
            throw ValidationException::withMessages(['payment' => 'حركة توريد: الغيها أو ارفضها من شاشة التوريد']);
        }

        if ($payment->kind === 'expense') {
            throw ValidationException::withMessages(['payment' => 'حركة مصروف: الغيها من شاشة المصروفات']);
        }

        return (new ModelResource($ledger->reverse($payment, 'reversal', $data['reason'])->refresh()))
            ->response()->setStatusCode(201);
    }
}
