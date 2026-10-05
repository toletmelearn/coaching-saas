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
use Illuminate\Support\Facades\Schema;
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

        // Migration-drift guard: if a table the app expects is missing, throw a
        // friendly error instead of letting the first request 500.
        // Only check on request paths, not CLI, so migrate commands work.
        // Pairs with docs/DEPLOY_RUNBOOK.md §1.1a — that check catches deploy-time
        // drift, this one local-dev drift (Phases 12, 13 and 15 each shipped a
        // migration that was never applied locally). See
        // tests/Feature/Console/SchemaDriftGuardTest.php.
        if (static::shouldCheckSchemaDrift()) {
            static::assertLocalSchemaCurrent();
        }
    }

    /**
     * The drift guard runs only in local/test environments and only on request
     * paths — never on console commands, so `php artisan migrate` can always fix
     * the very drift this guard reports.
     */
    public static function shouldCheckSchemaDrift(): bool
    {
        return app()->environment(['local', 'testing']) && ! app()->runningInConsole();
    }

    /**
     * Throw a friendly error when the schema is missing a table the code expects.
     * $table is parameterised only so the failure path is testable without
     * dropping a real table — MySQL DDL auto-commits, so a DROP inside a test
     * would not roll back and would poison the rest of the run.
     *
     * If the database itself is unreachable this returns quietly: the original
     * connection error then surfaces at the app's first real query exactly as it
     * did before this guard existed — a DB outage must not be rewritten as
     * schema drift.
     */
    public static function assertLocalSchemaCurrent(string $table = 'consents'): void
    {
        try {
            $schemaHasTable = Schema::hasTable($table);
        } catch (\Throwable) {
            return;
        }

        if (! $schemaHasTable) {
            throw new \RuntimeException(
                'The local database schema is out of date. Run `php artisan migrate` '.
                'and reload. See docs/DEPLOY_RUNBOOK.md §1.1a for the full check.'
            );
        }
    }
}
