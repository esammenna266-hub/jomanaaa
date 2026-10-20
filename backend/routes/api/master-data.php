<?php

use App\Modules\MasterData\Http\Controllers\CurrencyController;
use App\Modules\MasterData\Http\Controllers\CustomerController;
use App\Modules\MasterData\Http\Controllers\DriverController;
use App\Modules\MasterData\Http\Controllers\LookupController;
use App\Modules\MasterData\Http\Controllers\SeatLayoutController;
use App\Modules\MasterData\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

Route::get('currencies', [CurrencyController::class, 'index']);

Route::apiResource('customers', CustomerController::class);
Route::apiResource('vehicles', VehicleController::class);
Route::apiResource('drivers', DriverController::class);
Route::apiResource('seat-layouts', SeatLayoutController::class);

foreach (['border-points', 'destinations', 'clearing-agents'] as $lookup) {
    Route::apiResource($lookup, LookupController::class)->names("lookups.$lookup");
}
