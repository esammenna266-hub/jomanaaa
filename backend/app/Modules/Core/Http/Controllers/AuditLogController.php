<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Modules\Core\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('audit.view');

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'entity' => ['nullable', 'string', 'max:60'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name,username')
            ->when($filters['user_id'] ?? null, fn ($query, $value) => $query->where('user_id', $value))
            ->when($filters['branch_id'] ?? null, fn ($query, $value) => $query->where('branch_id', $value))
            ->when($filters['entity'] ?? null, fn ($query, $value) => $query->where('entity', $value))
            ->when($filters['entity_id'] ?? null, fn ($query, $value) => $query->where('entity_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->where('at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->where('at', '<=', $value))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ModelResource::collection($logs);
    }
}
