<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Modules\Finance\Models\Remittance;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\RemittanceService;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RemittanceController extends Controller
{
    public function __construct(private RemittanceService $remittances) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:sent,confirmed,rejected'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $remittances = $this->visible($request)
            ->with('fromTreasury.branch:id,name', 'toTreasury.branch:id,name')
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ModelResource::collection($remittances);
    }

    /**
     * Where a branch can send cash: the treasuries of the main warehouse. Branch staff cannot list other branches'
     * treasuries, so this gives them just the recipients they need.
     */
    public function destinations(): JsonResponse
    {
        Gate::authorize('remittance.send');

        $treasuries = Treasury::query()
            ->where('active', true)
            ->whereHas('branch', fn (Builder $branch) => $branch->where('is_main_warehouse', true))
            ->with('currency:id,code,name_ar', 'branch:id,name')
            ->orderBy('currency_id')
            ->get(['id', 'branch_id', 'currency_id', 'method']);

        return response()->json(['data' => $treasuries]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('remittance.send');

        $data = $request->validate([
            'from_treasury_id' => ['required', 'integer'],
            'to_treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'gt:0'],
        ]);

        $from = AccessScope::apply(Treasury::query(), $request->user(), 'remittance.send')->findOrFail($data['from_treasury_id']);
        $remittance = $this->remittances->send($request->user(), $from, Treasury::findOrFail($data['to_treasury_id']), $data['amount']);

        return (new ModelResource($remittance->refresh()))->response()->setStatusCode(201);
    }

    public function confirm(Request $request, int $id): ModelResource
    {
        Gate::authorize('remittance.confirm');

        return new ModelResource($this->remittances->confirm($request->user(), Remittance::findOrFail($id))->refresh());
    }

    public function reject(Request $request, int $id): ModelResource
    {
        Gate::authorize('remittance.confirm');

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return new ModelResource($this->remittances->reject($request->user(), Remittance::findOrFail($id), $data['reason'])->refresh());
    }

    /**
     * The confirming side sees every remittance; a sender sees those leaving the treasuries of their branch.
     */
    private function visible(Request $request): Builder
    {
        $user = $request->user();

        if ($user->scopeFor('remittance.confirm') === 'all') {
            return Remittance::query();
        }

        Gate::authorize('remittance.send');

        return Remittance::whereIn(
            'from_treasury_id',
            AccessScope::apply(Treasury::query(), $user, 'remittance.send')->select('treasuries.id'),
        );
    }
}
