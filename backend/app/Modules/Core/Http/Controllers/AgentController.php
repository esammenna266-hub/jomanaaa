<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Modules\Core\Models\Agent;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentController extends CrudController
{
    protected array $searchable = ['name', 'phone'];

    protected ?string $viewPermission = 'masterdata.view';

    protected string $managePermission = 'masterdata.manage';

    protected function modelClass(): string
    {
        return Agent::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'name' => [$model === null ? 'required' : 'sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', Rule::in(['EG', 'SD'])],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model): array
    {
        if (! empty($data['phone'])) {
            $data['phone'] = PhoneNumber::normalize($data['phone'], $data['country'] ?? 'EG');
        }

        return $data;
    }
}
