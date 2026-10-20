<?php

use App\Modules\Documents\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('documents', [DocumentController::class, 'index']);
Route::post('documents', [DocumentController::class, 'store']);
Route::get('documents/{id}/download', [DocumentController::class, 'download']);
Route::post('documents/{id}/edit-requests', [DocumentController::class, 'requestEdit']);

Route::get('edit-requests', [DocumentController::class, 'editRequests']);
Route::post('edit-requests/{id}/decide', [DocumentController::class, 'decideEdit']);
