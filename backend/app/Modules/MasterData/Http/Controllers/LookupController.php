<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Modules\MasterData\Models\BorderPoint;
use App\Modules\MasterData\Models\ClearingAgent;
use App\Modules\MasterData\Models\Destination;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Small master-data lists that share one shape: border points, destinations, clearing agents.
 * The list is chosen from the route name (lookups.<resource>.*).
 */
class LookupController extends CrudController
{
    private const RESOURCES = [
        'border-points' => [BorderPoint::class, 'border_points'],
        'destinations' => [Destination::class, 'destinations'],
        'clearing-agents' => [ClearingAgent::class, 'clearing_agents'],
    ];

    protected array $searchable = ['name'];

    protected ?string $viewPermission = 'masterdata.view';

    protected string $managePermission = 'masterdata.manage';

    protected function modelClass(): string
    {
        return self::RESOURCES[$this->resource()][0];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $table = self::RESOURCES[$this->resource()][1];

        $rules = [
            'name' => [
                $model === null ? 'required' : 'sometimes', 'string', 'max:120',
                Rule::unique($table, 'name')->ignore($model?->getKey()),
            ],
            'active' => ['sometimes', 'boolean'],
        ];

        if ($this->resource() === 'destinations') {
            $rules['country'] = ['nullable', Rule::in(['EG', 'SD'])];
        }

        if ($this->resource() === 'clearing-agents') {
            $rules['phone'] = ['nullable', 'string', 'max:20'];
        }

        return $rules;
    }

    protected function prepare(Request $request, array $data, ?Model $model): array
    {
        if (! empty($data['phone'])) {
            $data['phone'] = PhoneNumber::normalize($data['phone'], 'EG');
        }

        return $data;
    }

    private function resource(): string
    {
        return explode('.', (string) request()->route()?->getName())[1];
    }
}
