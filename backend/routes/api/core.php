<?php

use App\Modules\Core\Http\Controllers\AgentController;
use App\Modules\Core\Http\Controllers\AgentPriceController;
use App\Modules\Core\Http\Controllers\AuditLogController;
use App\Modules\Core\Http\Controllers\BranchController;
use App\Modules\Core\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

Route::apiResource('branches', BranchController::class);
Route::apiResource('agents', AgentController::class);
Route::get('agents/{agentId}/prices', [AgentPriceController::class, 'index']);
Route::post('agents/{agentId}/prices', [AgentPriceController::class, 'store']);
Route::put('agent-prices/{id}', [AgentPriceController::class, 'update']);
Route::delete('agent-prices/{id}', [AgentPriceController::class, 'destroy']);

Route::get('settings', [SettingController::class, 'index']);
Route::put('settings/{key}', [SettingController::class, 'update']);

Route::get('audit-logs', [AuditLogController::class, 'index']);
