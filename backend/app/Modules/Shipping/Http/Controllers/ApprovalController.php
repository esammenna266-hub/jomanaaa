<?php

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Modules\Shipping\Models\Approval;
use App\Modules\Shipping\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ApprovalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('shipment.approve_price');

        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'type' => ['nullable', 'in:price,discount,edit'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $approvals = Approval::query()
            ->with('requester:id,name', 'shipment:id,serial_no,customer_id,currency_id,price,discount,status', 'shipment.customer:id,name')
            ->where('status', $filters['status'] ?? 'pending')
            ->when($filters['type'] ?? null, fn ($query, $value) => $query->where('type', $value))
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return ModelResource::collection($approvals);
    }

    public function decide(Request $request, ShipmentService $shipments, int $id): ModelResource
    {
        Gate::authorize('shipment.approve_price');

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $approval = Approval::findOrFail($id);

        return new ModelResource(
            $shipments->decide($approval, $request->user(), $data['decision'] === 'approve', $data['reason'] ?? null)->refresh(),
        );
    }
}
