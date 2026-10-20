<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Modules\Core\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends CrudController
{
    protected array $searchable = ['name', 'phone', 'address'];

    protected string $managePermission = 'branch.manage';

    protected function modelClass(): string
    {
        return Branch::class;
    }

    /**
     * Branch names, phones and addresses are company information: external offices do not read them.
     */
    protected function scopedQuery(Request $request): Builder
    {
        abort_if($request->user()?->agent_id !== null, 403);

        return parent::scopedQuery($request);
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'country' => [$required, 'string', Rule::in(['EG', 'SD'])],
            'timezone' => ['sometimes', 'timezone'],
            'is_main_warehouse' => ['sometimes', 'boolean', function (string $attribute, mixed $value, \Closure $fail) use ($model): void {
                $exists = Branch::where('is_main_warehouse', true)
                    ->when($model, fn ($query) => $query->whereKeyNot($model->getKey()))
                    ->exists();

                if ($value && $exists) {
                    $fail('فيه مخزن رئيسي واحد بس');
                }
            }],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
