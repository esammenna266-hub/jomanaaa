<?php

namespace App\Providers;

use App\Modules\Documents\Pdf\BrowsershotPdfRenderer;
use App\Modules\Documents\Pdf\PdfRenderer;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PdfRenderer::class, BrowsershotPdfRenderer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Models live in app/Modules/<Module>/Models, factories stay flat in database/factories.
        Factory::guessFactoryNamesUsing(fn (string $model): string => 'Database\\Factories\\'.class_basename($model).'Factory');

        // Signed-in staff: generous for normal work, low enough to stop a runaway script or a stolen token.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(240)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Abilities are permission codes (e.g. "customer.create") from role_permissions / user_permissions.
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasPermission($ability) ? true : null;
        });
    }
}
