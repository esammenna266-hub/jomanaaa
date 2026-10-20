<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Currency;
use Illuminate\Http\JsonResponse;

class CurrencyController extends Controller
{
    /**
     * Currencies are reference data every screen needs to label amounts, so any signed-in user may read them.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Currency::where('active', true)->orderBy('id')->get(['id', 'code', 'name_ar'])]);
    }
}
