<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Treasury;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TreasuryController extends CrudController
{
    protected array $searchable = ['method'];

    protected ?string $viewPermission = 'treasury.view';

    protected string $managePermission = 'treasury.manage';

    protected function modelClass(): string
    {
        return Treasury::class;
    }

    /**
     * Balances are never stored: each treasury row carries the sum of its ledger movements.
     */
    protected function scopedQuery(Request $request): Builder
    {
        $balance = Payment::query()
            ->selectRaw("COALESCE(SUM(CASE direction WHEN 'in' THEN amount ELSE -amount END), 0)")
            ->whereColumn('payments.treasury_id', 'treasuries.id');

        return AccessScope::apply(Treasury::query(), $request->user(), 'treasury.view')
            ->select('treasuries.*')
            ->selectSub($balance, 'balance')
            ->with('currency:id,code,name_ar', 'branch:id,name');
    }

    /**
     * Branch, currency and method are fixed once a treasury exists because the ledger references them.
     */
    protected function rules(Request $request, ?Model $model): array
    {
        if ($model !== null) {
            return ['active' => ['required', 'boolean']];
        }

        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'currency_id' => [
                'required', 'integer', Rule::exists('currencies', 'id'),
                Rule::unique('treasuries')->where(fn ($query) => $query
                    ->where('branch_id', $request->input('branch_id'))
                    ->where('method', $request->input('method'))),
            ],
            'method' => ['required', Rule::in(['cash', 'instapay', 'vodafone_cash'])],
        ];
    }
}
