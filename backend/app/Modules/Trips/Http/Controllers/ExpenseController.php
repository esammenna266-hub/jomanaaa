<?php

namespace App\Modules\Trips\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Trips\Models\CargoTrip;
use App\Modules\Trips\Models\TripExpense;
use App\Modules\Trips\Services\ExpenseService;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $expenses) {}

    /**
     * Treasuries an expense can be paid from: the main warehouse's, plus any the user may already see.
     * The warehouse employee who records trip expenses cannot browse treasuries, so this gives them the choices.
     */
    public function treasuries(Request $request): JsonResponse
    {
        Gate::authorize('expense.manage');

        $user = $request->user();
        $visible = $user->hasPermission('treasury.view')
            ? AccessScope::apply(Treasury::query(), $user, 'treasury.view')->select('treasuries.id')
            : null;

        $balance = Payment::query()
            ->selectRaw("COALESCE(SUM(CASE direction WHEN 'in' THEN amount ELSE -amount END), 0)")
            ->whereColumn('payments.treasury_id', 'treasuries.id');

        $treasuries = Treasury::query()
            ->where('active', true)
            ->where(function (Builder $query) use ($visible): void {
                $query->whereHas('branch', fn (Builder $branch) => $branch->where('is_main_warehouse', true));
                if ($visible !== null) {
                    $query->orWhereIn('treasuries.id', $visible);
                }
            })
            ->select('treasuries.*')
            ->selectSub($balance, 'balance')
            ->with('currency:id,code,name_ar', 'branch:id,name')
            ->orderBy('treasuries.id')
            ->get();

        return response()->json(['data' => $treasuries]);
    }

    public function index(int $tripId): JsonResponse
    {
        Gate::authorize('expense.manage');

        $trip = CargoTrip::findOrFail($tripId);
        $expenses = $trip->expenses()->with('allocations.shipment:id,serial_no,consignee_name')->get();

        return response()->json(['data' => $expenses]);
    }

    /**
     * Without "allocations" the expense is split evenly across the trip's shipments (EX-01);
     * with them, the amounts must add up to the total (EX-02).
     */
    public function store(Request $request, int $tripId): JsonResponse
    {
        Gate::authorize('expense.manage');

        $data = $request->validate([
            'category' => ['required', 'string', 'max:60'],
            'point' => ['nullable', 'string', 'max:100'],
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'gt:0'],
            'allocations' => ['nullable', 'array', 'min:1'],
            'allocations.*.shipment_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);

        $manual = isset($data['allocations'])
            ? collect($data['allocations'])->pluck('amount', 'shipment_id')->all()
            : null;

        $expense = $this->expenses->record(
            $request->user(),
            CargoTrip::findOrFail($tripId),
            Treasury::findOrFail($data['treasury_id']),
            $data['category'],
            $data['point'] ?? null,
            $data['amount'],
            $manual,
        );

        return (new ModelResource($expense->refresh()->load('allocations')))->response()->setStatusCode(201);
    }

    public function void(Request $request, int $id): ModelResource
    {
        Gate::authorize('expense.manage');

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return new ModelResource($this->expenses->void($request->user(), TripExpense::findOrFail($id), $data['reason'])->refresh());
    }
}
