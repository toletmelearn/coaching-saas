<?php

namespace App\Providers;

use App\Auth\TenantUserProvider;
use App\Contracts\VideoProvider;
use App\Macros\BlueprintTenancyMacro;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\User;
use App\Policies\CoursePolicy;
use App\Policies\EnrolmentPolicy;
use App\Policies\UserPolicy;
use App\Services\Video\BunnyVideoProvider;
use App\Services\Video\FakeVideoProvider;
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

        $this->app->bind(VideoProvider::class, function () {
            return match (config('coaching.video_driver')) {
                'bunny' => new BunnyVideoProvider,
                default => new FakeVideoProvider,
            };
        });
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
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(Enrolment::class, EnrolmentPolicy::class);
    }
}
