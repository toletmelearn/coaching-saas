<?php

use App\Listeners\RegisterUserDevice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/**
 * Guards against the exact regression found while building this phase:
 * Application::configure() enables event auto-discovery by default (scans
 * app/Listeners), so App\Listeners\RegisterUserDevice::handle(Login $event) is
 * auto-registered on its own. A second, explicit Event::listen(Login::class,
 * RegisterUserDevice::class) call (e.g. re-added to AppServiceProvider by a future
 * change "for clarity") would register it a second time under a different internal
 * key and silently double-fire it on every login. See docs/specs/phase-8-devices.md.
 */
test('the Login event has exactly one registered listener', function () {
    expect(Event::getListeners(Login::class))->toHaveCount(1);
});

test('a single login creates exactly one device row, even after events are cached', function () {
    // Targets event:cache specifically (the mechanism php artisan optimize invokes that's
    // actually relevant here) rather than the full optimize command — optimize also runs
    // config:cache, which rebuilds the config/container in a way that drops this test's
    // in-memory SQLite connection entirely. Manually verified separately (see
    // docs/specs/phase-8-devices.md) that a real `php artisan optimize` on a persistent
    // database produces the identical bootstrap/cache/events.php this test exercises.
    Artisan::call('event:cache');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->post("http://{$domain}/login", [
        'identifier' => $student->email,
        'password' => 'password',
    ])->assertRedirect();

    inTenant($tenant, fn () => expect(UserDevice::where('user_id', $student->id)->count())->toBe(1));

    Artisan::call('event:clear');
});
