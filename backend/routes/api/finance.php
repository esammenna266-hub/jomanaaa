<?php

use App\Modules\Finance\Http\Controllers\AgentAccountController;
use App\Modules\Finance\Http\Controllers\PaymentController;
use App\Modules\Finance\Http\Controllers\RemittanceController;
use App\Modules\Finance\Http\Controllers\TreasuryController;
use Illuminate\Support\Facades\Route;

Route::apiResource('treasuries', TreasuryController::class)->except('destroy');

Route::get('payments', [PaymentController::class, 'index']);
Route::post('payments', [PaymentController::class, 'store']);
Route::post('payments/{id}/reverse', [PaymentController::class, 'reverse']);

Route::get('agents/{agentId}/account', [AgentAccountController::class, 'show']);
Route::post('agents/{agentId}/settlements', [AgentAccountController::class, 'settle']);

Route::get('remittances/destinations', [RemittanceController::class, 'destinations']);
Route::get('remittances', [RemittanceController::class, 'index']);
Route::post('remittances', [RemittanceController::class, 'store']);
Route::post('remittances/{id}/confirm', [RemittanceController::class, 'confirm']);
Route::post('remittances/{id}/reject', [RemittanceController::class, 'reject']);
