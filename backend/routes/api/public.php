<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Shipping\Http\Controllers\TrackingController;
use App\Modules\Trips\Http\Controllers\CargoTripController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Public tracking page: status and dates only.
Route::get('track/{code}', [TrackingController::class, 'show'])->middleware('throttle:60,1');

// Short-lived signed link to a driver's fingerprint image.
Route::get('cargo-trips/{id}/fingerprint', [CargoTripController::class, 'fingerprint'])
    ->name('cargo-trips.fingerprint')->middleware('signed');
