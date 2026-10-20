<?php

use App\Modules\Booking\Http\Controllers\BookingController;
use App\Modules\Booking\Http\Controllers\PassengerTripController;
use App\Modules\Booking\Http\Controllers\ServiceBookingController;
use Illuminate\Support\Facades\Route;

Route::get('trips', [PassengerTripController::class, 'index']);
Route::post('trips', [PassengerTripController::class, 'store']);
Route::get('trips/{id}', [PassengerTripController::class, 'show']);
Route::put('trips/{id}', [PassengerTripController::class, 'update']);
Route::get('trips/{id}/seats', [PassengerTripController::class, 'seats']);
Route::get('trips/{id}/passengers', [PassengerTripController::class, 'passengers']);
Route::get('trips/{id}/express-shipments', [PassengerTripController::class, 'expressShipments']);
Route::post('trips/{id}/depart', [PassengerTripController::class, 'depart']);
Route::post('trips/{id}/complete', [PassengerTripController::class, 'complete']);
Route::post('trips/{id}/cancel', [PassengerTripController::class, 'cancel']);

Route::get('bookings', [BookingController::class, 'index']);
Route::post('bookings', [BookingController::class, 'store']);
Route::get('bookings/{id}', [BookingController::class, 'show']);
Route::post('bookings/{id}/cancel', [BookingController::class, 'cancel']);

Route::get('service-bookings', [ServiceBookingController::class, 'index']);
Route::post('service-bookings', [ServiceBookingController::class, 'store']);
Route::get('service-bookings/{id}', [ServiceBookingController::class, 'show']);
Route::post('service-bookings/{id}/collect', [ServiceBookingController::class, 'collect']);
Route::post('service-bookings/{id}/complete', [ServiceBookingController::class, 'complete']);
Route::post('service-bookings/{id}/cancel', [ServiceBookingController::class, 'cancel']);
