<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Modules\MasterData\Models\SeatLayout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class SeatLayoutController extends CrudController
{
    protected ?string $viewPermission = 'masterdata.view';

    protected string $managePermission = 'masterdata.manage';

    protected function modelClass(): string
    {
        return SeatLayout::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80'],
            'seats_count' => [$required, 'integer', 'min:1', 'max:200'],
            'layout_json' => [$required, 'array'],
        ];
    }
}
