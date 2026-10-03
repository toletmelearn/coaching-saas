<?php

namespace App\Providers;

use App\Auth\TenantUserProvider;
use App\Contracts\VideoProvider;
use App\Macros\BlueprintTenancyMacro;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\LiveClass;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\CoursePolicy;
use App\Policies\EnrolmentPolicy;
use App\Policies\LiveClassPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\TenantPolicy;
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
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(LiveClass::class, LiveClassPolicy::class);

        // App\Listeners\RegisterUserDevice (Phase 8) needs no explicit Event::listen()
        // call here — Application::configure() enables event auto-discovery by default
        // (->withEvents(), never overridden in bootstrap/app.php), which scans
        // app/Listeners and wires handle(Login $event) up on its own. Registering it
        // again here would double-fire it (confirmed while debugging: two listener
        // entries — the auto-discovered "Class@handle" form and a manually added
        // "Class" form — created two device rows from a single login).
    }
}
