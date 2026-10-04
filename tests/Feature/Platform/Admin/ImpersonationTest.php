<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Admin\ServiceSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Phase 13 feature E — "Login as owner": a signed, single-use, 60-second URL
 * minted on the tenant host, consumed by ImpersonationController, audited
 * before the session is created. Covers mint, consume, replay, expiry,
 * wrong-host, wrong-role, cross-tenant and no-owner.
 */

/**
 * @return array{0: Tenant, 1: User}
 */
function p13ImpersonationTenant(): array
{
    $tenant = Tenant::factory()->create(['name' => 'Impersonate Institute']);
    $tenant->domains()->create([
        'domain' => 'impersonate.coaching.test',
        'type' => 'subdomain',
        'is_primary' => true,
    ]);

    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    return [$tenant, $owner];
}

function p13MintLink(TestCase $t, PlatformAdmin $admin, Tenant $tenant): string
{
    $response = $t->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/login-as");

    $response->assertRedirect();

    return (string) $response->headers->get('Location');
}

test('login as owner mints a signed one-time url on the tenant host', function () {
    [$tenant] = p13ImpersonationTenant();
    $admin = PlatformAdmin::factory()->create();

    $location = p13MintLink($this, $admin, $tenant);

    expect($location)->toStartWith('http://impersonate.coaching.test/admin/impersonate?');
    expect($location)->toContain('signature=');
    expect($location)->toContain('expires=');
    expect($location)->toContain('user=');
    expect($location)->toContain("admin={$admin->id}");
});

test('consuming the link logs the platform admin in as the owner and audits first', function () {
    [$tenant, $owner] = p13ImpersonationTenant();
    $admin = PlatformAdmin::factory()->create();

    $location = p13MintLink($this, $admin, $tenant);

    $this->get($location)->assertRedirect('http://impersonate.coaching.test/dashboard');

    $this->assertAuthenticatedAs($owner, 'tenant');

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'impersonate',
        'target_type' => 'user',
        'target_id' => $owner->id,
        'admin_id' => $admin->id,
    ]);

    // Exactly one row — consuming once is the contract.
    $this->assertDatabaseCount('admin_audit_logs', 1);
});

test('the link cannot be replayed after first use', function () {
    [$tenant] = p13ImpersonationTenant();
    $admin = PlatformAdmin::factory()->create();

    $location = p13MintLink($this, $admin, $tenant);

    $this->get($location)->assertRedirect('http://impersonate.coaching.test/dashboard');

    freshRequestCycle();

    $this->get($location)->assertForbidden();

    // The single-use burn happened before any login, so the replay audit count
    // stays at exactly one (the original consumption).
    $this->assertDatabaseCount('admin_audit_logs', 1);
});

test('an expired link is rejected', function () {
    [$tenant] = p13ImpersonationTenant();
    $admin = PlatformAdmin::factory()->create();

    $location = p13MintLink($this, $admin, $tenant);

    $this->travel(61)->seconds();

    $this->get($location)->assertForbidden();

    $this->assertDatabaseCount('admin_audit_logs', 0);
});

test('a link minted for one tenant host dies on another host', function () {
    [$tenantA] = p13ImpersonationTenant();

    $tenantB = Tenant::factory()->create();
    $tenantB->domains()->create(['domain' => 'other-impersonate.coaching.test', 'type' => 'subdomain']);

    $admin = PlatformAdmin::factory()->create();
    $location = p13MintLink($this, $admin, $tenantA);

    $wrongHost = str_replace(
        'http://impersonate.coaching.test',
        'http://other-impersonate.coaching.test',
        $location,
    );

    $this->get($wrongHost)->assertForbidden();

    $this->assertDatabaseCount('admin_audit_logs', 0);
});

test('a valid signature cannot impersonate a non-owner', function () {
    [$tenant] = p13ImpersonationTenant();
    $student = inTenant($tenant, fn () => User::factory()->create());

    // Hand-mint exactly what the endpoint would sign, but for a student id —
    // proves the role check, not just the signature, guards the endpoint.
    URL::forceRootUrl('http://impersonate.coaching.test');
    try {
        $url = URL::temporarySignedRoute('admin.impersonate', now()->addSeconds(60), [
            'user' => (int) $student->id,
            'admin' => 1,
        ]);
    } finally {
        URL::forceRootUrl((string) config('app.url'));
    }

    $this->get($url)->assertForbidden();
    $this->assertDatabaseCount('admin_audit_logs', 0);
});

test('a user id from another tenant is a 404, never a login', function () {
    [$tenantA] = p13ImpersonationTenant();

    $tenantB = Tenant::factory()->create();
    $tenantB->domains()->create(['domain' => 'victim.coaching.test', 'type' => 'subdomain']);
    $victimOwner = inTenant($tenantB, fn () => User::factory()->owner()->create());

    URL::forceRootUrl('http://impersonate.coaching.test');
    try {
        $url = URL::temporarySignedRoute('admin.impersonate', now()->addSeconds(60), [
            'user' => (int) $victimOwner->id,
            'admin' => 1,
        ]);
    } finally {
        URL::forceRootUrl((string) config('app.url'));
    }

    $this->get($url)->assertNotFound();
    $this->assertDatabaseCount('admin_audit_logs', 0);
});

test('an institute without an owner cannot be impersonated', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'ownerless.coaching.test', 'type' => 'subdomain']);

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/login-as")
        ->assertRedirect("http://coaching.test/admin/institutes/{$tenant->id}")
        ->assertSessionHasErrors('owner');
});

test('a tenant user cannot reach the login-as mint endpoint', function () {
    [$tenant, $owner] = p13ImpersonationTenant();

    $this->actingAs($owner, 'tenant')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/login-as")
        ->assertForbidden();
});

test('the bunny usage page renders live figures for the tenant library', function () {
    Http::fake([
        'api.bunny.net/*' => Http::response([
            'Id' => 12345,
            'VideoCount' => 7,
            'StorageUsage' => 1073741824,
            'TrafficUsage' => 2147483648,
        ], 200),
    ]);

    [$tenant] = p13ImpersonationTenant();
    $tenant->forceFill(['bunny_library_id' => 12345])->save();

    ServiceSettings::set('bunny_account_api_key', 'usage-key');

    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->get("http://coaching.test/admin/institutes/{$tenant->id}/bunny-usage");

    $response->assertOk();
    $response->assertSee('1 GB');
    $response->assertSee('2 GB');
    $response->assertSee('7');
    $response->assertSee('12345');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.bunny.net/videolibrary/12345'
        && in_array('usage-key', (array) $request->header('AccessKey'), true));
});

test('the bunny usage page degrades gracefully without a provisioned library', function () {
    [$tenant] = p13ImpersonationTenant(); // bunny_library_id is null

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->get("http://coaching.test/admin/institutes/{$tenant->id}/bunny-usage")
        ->assertOk()
        ->assertSee(__('platform.admin.bunny_usage.unprovisioned'));
});

test('the bunny usage page reports a missing account key without calling out', function () {
    config(['services.bunny.account_api_key' => null]);

    [$tenant] = p13ImpersonationTenant();
    $tenant->forceFill(['bunny_library_id' => 12345])->save();

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->get("http://coaching.test/admin/institutes/{$tenant->id}/bunny-usage")
        ->assertOk()
        ->assertSee(__('platform.admin.bunny_usage.error_missing_key'));
});
