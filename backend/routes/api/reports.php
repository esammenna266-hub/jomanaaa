<?php

use App\Modules\Reports\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('reports/dashboard', [ReportController::class, 'dashboard']);
Route::get('reports/{report}', [ReportController::class, 'show']);
