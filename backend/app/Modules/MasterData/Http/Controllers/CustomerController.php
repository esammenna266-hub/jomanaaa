<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Modules\MasterData\Models\Customer;
use App\Support\AccessScope;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends CrudController
{
    protected array $searchable = ['name', 'phone', 'passport_no'];

    protected ?string $viewPermission = 'customer.view';

    protected string $managePermission = 'customer.create';

    protected function modelClass(): string
    {
        return Customer::class;
    }

    protected function scopedQuery(Request $request): Builder
    {
        return AccessScope::apply(Customer::query(), $request->user(), 'customer.view');
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'name' => [$model === null ? 'required' : 'sometimes', 'string', 'max:150'],
            'phone' => [$model === null ? 'required' : 'sometimes', 'string', 'max:20'],
            'passport_no' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', Rule::in(['EG', 'SD'])],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'agent_id' => ['nullable', 'integer', Rule::exists('agents', 'id')],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model): array
    {
        $user = $request->user();

        if (isset($data['phone'])) {
            try {
                $data['phone'] = PhoneNumber::normalize($data['phone'], $data['country'] ?? $model?->country ?? 'EG');
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['phone' => 'رقم التليفون غير صحيح']);
            }
        }

        // Staff and agents can only register customers inside their own scope.
        if ($model === null) {
            if ($user->agent_id !== null) {
                $data['agent_id'] = $user->agent_id;
            } elseif ($user->scopeFor('customer.view') === 'branch') {
                $data['branch_id'] = $user->branch_id;
            }
        } else {
            unset($data['agent_id'], $data['branch_id']);
        }

        $phone = $data['phone'] ?? $model?->phone;
        $agentId = array_key_exists('agent_id', $data) ? $data['agent_id'] : $model?->agent_id;
        $duplicate = Customer::where('phone', $phone)
            ->where('agent_id', $agentId)
            ->when($model, fn (Builder $query) => $query->whereKeyNot($model->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['phone' => 'العميل ده متسجل قبل كده بنفس رقم التليفون']);
        }

        return $data;
    }
}
