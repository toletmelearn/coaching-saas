<?php

namespace App\Providers;

use App\Auth\TenantUserProvider;
use App\Macros\BlueprintTenancyMacro;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        BlueprintTenancyMacro::register();

        Auth::provider('tenant_eloquent', function ($app, array $config) {
            return new TenantUserProvider($app['hash'], $config['model']);
        });

        Gate::policy(User::class, UserPolicy::class);
    }
}
