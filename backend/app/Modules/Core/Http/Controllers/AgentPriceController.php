<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\AgentPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The price list of one external office: one row per service, route and currency.
 */
class AgentPriceController extends Controller
{
    private const MONEY = '/^\d{1,12}(\.\d{1,2})?$/';

    public function index(int $agentId): AnonymousResourceCollection
    {
        Gate::authorize('agent_price.manage');

        Agent::findOrFail($agentId);

        return ModelResource::collection(AgentPrice::where('agent_id', $agentId)->orderBy('service')->orderBy('route')->get());
    }

    public function store(Request $request, int $agentId): JsonResponse
    {
        Gate::authorize('agent_price.manage');

        Agent::findOrFail($agentId);
        $data = $request->validate($this->rules($agentId, null));
        $this->assertFree($agentId, $data, null);

        $price = AgentPrice::create([...$data, 'agent_id' => $agentId, 'created_by' => $request->user()->id]);

        return (new ModelResource($price->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): ModelResource
    {
        Gate::authorize('agent_price.manage');

        $price = AgentPrice::findOrFail($id);
        $data = $request->validate($this->rules($price->agent_id, $price));
        $this->assertFree($price->agent_id, [...$price->only(['service', 'route', 'currency_id']), ...$data], $price);
        $price->update($data);

        return new ModelResource($price->refresh());
    }

    /**
     * Prices are switched off, not deleted: old bookings were sold on them.
     */
    public function destroy(int $id): JsonResponse
    {
        Gate::authorize('agent_price.manage');

        AgentPrice::findOrFail($id)->update(['active' => false]);

        return response()->json(['message' => 'تم الإيقاف']);
    }

    /**
     * One price per service, route and currency (the table has a unique key; say so before it throws).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertFree(int $agentId, array $data, ?AgentPrice $except): void
    {
        $clash = AgentPrice::where('agent_id', $agentId)
            ->where('service', $data['service'])
            ->where('route', $data['route'])
            ->where('currency_id', $data['currency_id'])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages(['route' => 'فيه سعر للمكتب ده على الخط ده بنفس الخدمة والعملة']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(int $agentId, ?AgentPrice $price): array
    {
        $required = $price === null ? 'required' : 'sometimes';

        return [
            'service' => [$required, Rule::in(['bus', 'flight', 'ship', 'express', 'cargo'])],
            'route' => [$required, 'string', 'max:120'],
            'price' => [$required, 'regex:'.self::MONEY],
            'currency_id' => [$required, 'integer', 'exists:currencies,id'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
