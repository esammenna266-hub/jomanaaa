<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Modules\Core\Models\Agent;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\AgentAccountService;
use App\Support\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * An external office's account: what it owes, what it paid, and recording a payment.
 */
class AgentAccountController extends Controller
{
    private const MONEY = '/^\d{1,12}(\.\d{1,2})?$/';

    public function __construct(private AgentAccountService $accounts) {}

    /**
     * The company side (price managers) sees any office; an office sees only its own account.
     */
    public function show(Request $request, int $agentId): JsonResponse
    {
        $user = $request->user();

        if ($user->agent_id === null || $user->agent_id !== $agentId) {
            Gate::authorize('agent_price.manage');
        }

        $agent = Agent::findOrFail($agentId);

        return response()->json(['data' => [
            'agent' => new ModelResource($agent),
            'balances' => $this->accounts->balances($agent->id),
            'settlements' => ModelResource::collection($this->accounts->settlements($agent->id)),
        ]]);
    }

    public function settle(Request $request, int $agentId): JsonResponse
    {
        Gate::authorize('payment.collect');

        $data = $request->validate([
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'amount' => ['required', 'regex:'.self::MONEY, 'gt:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $agent = Agent::findOrFail($agentId);
        $treasury = AccessScope::apply(Treasury::query(), $request->user(), 'payment.collect')->findOrFail($data['treasury_id']);

        $payment = $this->accounts->settle($agent, $treasury, $data['amount'], $data['notes'] ?? null);

        return (new ModelResource($payment->refresh()))->response()->setStatusCode(201);
    }
}
