<?php

namespace App\Providers;

use App\Support\Api\AdminApi;
use App\Support\Api\ApiGuard;
use App\Support\Api\ApiTokens;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ApiTokens::class, fn (Application $app) => new ApiTokens($app['session.store']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('ochantier-api', fn (Application $app) => new ApiGuard($app->make(ApiTokens::class), $app->make(AdminApi::class)));
    }
}
