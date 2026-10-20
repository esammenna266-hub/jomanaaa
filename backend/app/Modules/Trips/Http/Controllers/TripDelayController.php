<?php

namespace App\Modules\Trips\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Modules\Trips\Models\CargoTrip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Trip delays are recorded only (open point 7): they are not charged to any customer account.
 */
class TripDelayController extends Controller
{
    public function index(int $tripId): JsonResponse
    {
        Gate::authorize('expense.manage');

        return response()->json(['data' => CargoTrip::findOrFail($tripId)->delays]);
    }

    public function store(Request $request, int $tripId): JsonResponse
    {
        Gate::authorize('expense.manage');

        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'cost' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'currency_id' => ['nullable', 'required_with:cost', 'integer', 'exists:currencies,id'],
            'responsible' => ['required', Rule::in(['customer', 'driver', 'company'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $delay = CargoTrip::findOrFail($tripId)->delays()->create([
            ...$data,
            'cost' => $data['cost'] ?? '0',
            'created_by' => $request->user()->id,
        ]);

        return (new ModelResource($delay->refresh()))->response()->setStatusCode(201);
    }
}
