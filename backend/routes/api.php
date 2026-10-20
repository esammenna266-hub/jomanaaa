<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Routes are split per module under routes/api/. public.php needs no token;
| every other file is loaded inside the authenticated group.
*/

Route::prefix('v1')->group(function (): void {
    require __DIR__.'/api/public.php';

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
        foreach (['identity', 'core', 'master-data', 'shipping', 'trips', 'finance', 'booking', 'documents', 'reports'] as $module) {
            require __DIR__."/api/{$module}.php";
        }
    });
});
