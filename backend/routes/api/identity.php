<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('auth/me', [AuthController::class, 'me']);
Route::post('auth/logout', [AuthController::class, 'logout']);
Route::post('auth/change-password', [AuthController::class, 'changePassword']);

Route::get('roles', [RoleController::class, 'index']);

Route::post('users/{id}/reset-password', [UserController::class, 'resetPassword']);
Route::apiResource('users', UserController::class);
