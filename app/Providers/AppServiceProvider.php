<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::createAssetPathsUsing(function (string $path, ?bool $secure = null): string {
            if (app()->runningInConsole()) {
                return asset($path, $secure);
            }

            $request = request();
            $base = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');

            return $base.'/'.ltrim($path, '/');
        });

        Vite::prefetch(concurrency: 3);
    }
}
