<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends CrudController
{
    protected array $searchable = ['name', 'username'];

    protected ?string $viewPermission = 'user.manage';

    protected string $managePermission = 'user.manage';

    protected function modelClass(): string
    {
        return User::class;
    }

    protected function scopedQuery(Request $request): Builder
    {
        return User::query()->with('role:id,code,name_ar', 'branch:id,name', 'agent:id,name');
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'username' => [$required, 'string', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($model?->getKey())],
            'password' => [$model === null ? 'required' : 'prohibited', 'string', 'min:8'],
            'role_id' => [$required, 'integer', Rule::exists('roles', 'id')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'agent_id' => ['nullable', 'integer', Rule::exists('agents', 'id')],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model): array
    {
        $roleId = $data['role_id'] ?? $model?->role_id;
        $roleCode = Role::whereKey($roleId)->value('code');
        $branchId = array_key_exists('branch_id', $data) ? $data['branch_id'] : $model?->branch_id;
        $agentId = array_key_exists('agent_id', $data) ? $data['agent_id'] : $model?->agent_id;

        if ($roleCode === 'agent' && $agentId === null) {
            throw ValidationException::withMessages(['agent_id' => 'حساب المكتب الخارجي لازم يتربط بمكتب']);
        }

        if ($roleCode === 'branch_staff' && $branchId === null) {
            throw ValidationException::withMessages(['branch_id' => 'موظف الفرع لازم يتربط بفرع']);
        }

        if ($roleCode === 'agent' && $branchId !== null) {
            throw ValidationException::withMessages(['branch_id' => 'حساب المكتب الخارجي ميتربطش بفرع']);
        }

        if ($roleCode !== 'agent' && $agentId !== null) {
            throw ValidationException::withMessages(['agent_id' => 'الربط بمكتب خارجي لدور المكتب الخارجي بس']);
        }

        if (isset($data['password'])) {
            $data['password_hash'] = $data['password'];
            unset($data['password']);
        }

        return $data;
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        Gate::authorize($this->managePermission);

        $user = User::findOrFail($id);
        $temporary = Str::password(12, symbols: false);

        $user->update(['password_hash' => $temporary, 'must_change_password' => true]);
        $user->tokens()->delete();

        return response()->json(['temporary_password' => $temporary]);
    }
}
