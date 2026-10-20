<?php

use App\Modules\Trips\Http\Controllers\CargoTripController;
use App\Modules\Trips\Http\Controllers\ExpenseController;
use App\Modules\Trips\Http\Controllers\TripDelayController;
use Illuminate\Support\Facades\Route;

Route::get('cargo-trips', [CargoTripController::class, 'index']);
Route::post('cargo-trips', [CargoTripController::class, 'store']);
Route::get('cargo-trips/{id}', [CargoTripController::class, 'show']);
Route::get('cargo-trips/{id}/manifest', [CargoTripController::class, 'manifest']);
Route::get('cargo-trips/{id}/waybills', [CargoTripController::class, 'waybills']);
Route::post('cargo-trips/{id}/fingerprint', [CargoTripController::class, 'replaceFingerprint']);
Route::post('cargo-trips/{id}/stage', [CargoTripController::class, 'stage']);
Route::post('cargo-trips/{id}/cancel', [CargoTripController::class, 'cancel']);

Route::get('expenses/treasuries', [ExpenseController::class, 'treasuries']);
Route::get('cargo-trips/{tripId}/expenses', [ExpenseController::class, 'index']);
Route::post('cargo-trips/{tripId}/expenses', [ExpenseController::class, 'store']);
Route::post('expenses/{id}/void', [ExpenseController::class, 'void']);

Route::get('cargo-trips/{tripId}/delays', [TripDelayController::class, 'index']);
Route::post('cargo-trips/{tripId}/delays', [TripDelayController::class, 'store']);
