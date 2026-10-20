<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Modules\MasterData\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleController extends CrudController
{
    protected array $searchable = ['plate_no', 'owner_name'];

    protected ?string $viewPermission = 'masterdata.view';

    protected string $managePermission = 'masterdata.manage';

    protected function modelClass(): string
    {
        return Vehicle::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model === null ? 'required' : 'sometimes';
        $category = $request->input('category', $model?->category);

        return [
            'plate_no' => [$required, 'string', 'max:30', Rule::unique('vehicles', 'plate_no')->ignore($model?->getKey())],
            'category' => [$required, Rule::in(['jumbo', 'single', 'trailer', 'bus'])],
            'body' => ['nullable', Rule::in(['closed', 'open'])],
            'min_ton' => ['nullable', 'numeric', 'min:0'],
            'max_ton' => ['nullable', 'numeric', 'gte:min_ton'],
            'seats' => [$category === 'bus' ? 'required' : 'nullable', 'integer', 'min:1'],
            'seat_layout_id' => ['nullable', 'integer', Rule::exists('seat_layouts', 'id')],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'ownership' => ['sometimes', Rule::in(['owned', 'rented'])],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
