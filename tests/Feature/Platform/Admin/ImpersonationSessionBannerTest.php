<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

/**
 * Phase 16 §3 — an impersonated owner session shows an always-visible "Impersonating <owner>"
 * banner with an Exit button, and expires 60 minutes after it started. Reuses the Phase 13
 * mint helpers from ImpersonationTest.php (p13ImpersonationTenant, p13MintLink).
 */
function p16ImpersonationTenant(): array
{
    $tenant = Tenant::factory()->create(['name' => 'Banner Institute']);
    $tenant->domains()->create(['domain' => 'impersonate.coaching.test', 'type' => 'subdomain', 'is_primary' => true]);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    return [$tenant, $owner];
}

function p16MintLink(TestCase $t, PlatformAdmin $admin, Tenant $tenant): string
{
    $response = $t->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/login-as");
    $response->assertRedirect();

    return (string) $response->headers->get('Location');
}
function p16ImpersonateOwner(TestCase $t): array
{
    [$tenant, $owner] = p16ImpersonationTenant();
    $admin = PlatformAdmin::factory()->create();

    $location = p16MintLink($t, $admin, $tenant);
    $t->get($location)->assertRedirect('/dashboard');

    return [$owner, 'http://impersonate.coaching.test'];
}

test('an impersonated owner sees the "Impersonating <owner>" banner with an Exit button on the dashboard', function () {
    [$owner, $origin] = p16ImpersonateOwner($this);

    $response = $this->get("{$origin}/dashboard");

    $response->assertOk();
    $response->assertSee(__('impersonation.banner', ['name' => $owner->name]), false);
    $response->assertSee(__('impersonation.exit'), false);
});

test('a normal owner session shows no impersonation banner, while the impersonated one does (positive control in the same test)', function () {
    [$owner, $origin] = p16ImpersonateOwner($this);
    $this->get("{$origin}/dashboard")->assertSee(__('impersonation.exit'), false);

    freshRequestCycle();
    $this->flushSession();

    $response = $this->actingAs($owner, 'tenant')->get('http://impersonate.coaching.test/dashboard');

    $response->assertOk();
    $response->assertDontSee(__('impersonation.exit'), false);
});

test('Exit ends the impersonated session and returns the browser to the login page (positive control: banner present before exit)', function () {
    [$owner, $origin] = p16ImpersonateOwner($this);

    $this->get("{$origin}/dashboard")->assertSee(__('impersonation.exit'), false);

    $this->post("{$origin}/impersonation/exit")->assertRedirect("{$origin}/login");

    freshRequestCycle();

    $this->get("{$origin}/dashboard")->assertRedirect("{$origin}/login");
});

test('an impersonated session expires after 60 minutes (positive control: still valid at 59 minutes)', function () {
    [$owner, $origin] = p16ImpersonateOwner($this);

    $this->travel(59)->minutes();
    $this->get("{$origin}/dashboard")->assertOk();

    $this->travel(2)->minutes();
    freshRequestCycle();

    $this->get("{$origin}/dashboard")->assertRedirect("{$origin}/login");
});
