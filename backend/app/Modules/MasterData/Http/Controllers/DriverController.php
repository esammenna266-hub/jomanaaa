<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Modules\MasterData\Models\Driver;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DriverController extends CrudController
{
    protected array $searchable = ['name', 'phone', 'license_no', 'id_no'];

    protected ?string $viewPermission = 'masterdata.view';

    protected string $managePermission = 'masterdata.manage';

    protected function modelClass(): string
    {
        return Driver::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'name' => [$model === null ? 'required' : 'sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'license_no' => ['nullable', 'string', 'max:40'],
            'id_type' => ['nullable', Rule::in(['national_id', 'passport', 'other'])],
            'id_no' => ['nullable', 'string', 'max:40'],
            'id_issue_date' => ['nullable', 'date'],
            'id_expiry_date' => ['nullable', 'date', 'after_or_equal:id_issue_date'],
            'is_international' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(Request $request, array $data, ?Model $model): array
    {
        if (! empty($data['phone'])) {
            try {
                $data['phone'] = PhoneNumber::normalize($data['phone']);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['phone' => 'رقم التليفون غير صحيح']);
            }
        }

        return $data;
    }
}
