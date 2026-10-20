<?php

use App\Modules\Shipping\Http\Controllers\ApprovalController;
use App\Modules\Shipping\Http\Controllers\ShipmentController;
use App\Modules\Shipping\Http\Controllers\ShipmentOptionsController;
use App\Modules\Shipping\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::get('shipping/options', ShipmentOptionsController::class);

Route::get('shipments', [ShipmentController::class, 'index']);
Route::post('shipments', [ShipmentController::class, 'store']);
Route::get('shipments/arrived', [ShipmentController::class, 'arrived']);
Route::post('shipments/{id}/deliver', [ShipmentController::class, 'deliver']);
Route::get('shipments/{id}', [ShipmentController::class, 'show']);
Route::put('shipments/{id}', [ShipmentController::class, 'update']);
Route::post('shipments/{id}/submit', [ShipmentController::class, 'submit']);
Route::post('shipments/{id}/receive', [ShipmentController::class, 'receive']);
Route::post('shipments/{id}/cancel', [ShipmentController::class, 'cancel']);
Route::post('shipments/{id}/installments', [ShipmentController::class, 'scheduleInstallments']);

Route::get('transfers', [TransferController::class, 'index']);
Route::post('transfers', [TransferController::class, 'store']);
Route::get('transfers/lookup', [TransferController::class, 'lookup']);
Route::get('transfers/{id}', [TransferController::class, 'show']);
Route::post('transfers/{id}/receive', [TransferController::class, 'receive']);

Route::get('approvals', [ApprovalController::class, 'index']);
Route::post('approvals/{id}/decide', [ApprovalController::class, 'decide']);
