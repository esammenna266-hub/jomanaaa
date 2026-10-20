<?php

namespace App\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query-level data scoping: "فرعه" (branch) and "بتاعه" (own agent) are enforced here, not in the UI.
 */
final class AccessScope
{
    public static function apply(
        Builder $query,
        User $user,
        string $permission,
        string $branchColumn = 'branch_id',
        string $agentColumn = 'agent_id',
    ): Builder {
        return match ($user->scopeFor($permission)) {
            'all' => $query,
            'branch' => $user->branch_id !== null
                ? $query->where($branchColumn, $user->branch_id)
                : $query->whereRaw('1 = 0'),
            'own' => $user->agent_id !== null
                ? $query->where($agentColumn, $user->agent_id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
