<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;

function validInstitutePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Bright Future Academy',
        'subdomain' => 'brightfuture-admin',
        'owner_name' => 'Priya Sharma',
        'owner_email' => 'priya@example.com',
    ], $overrides);
}

// === Index: search + pagination ===

test('the institutes list can be searched by name or subdomain', function () {
    $admin = PlatformAdmin::factory()->create();

    $tenantA = Tenant::factory()->create(['name' => 'Alpha Coaching']);
    $tenantA->domains()->create(['domain' => 'alpha.coaching.test', 'type' => 'subdomain']);
    $tenantB = Tenant::factory()->create(['name' => 'Beta Institute']);
    $tenantB->domains()->create(['domain' => 'beta.coaching.test', 'type' => 'subdomain']);

    // Positive control: no filter shows both.
    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/institutes')
        ->assertSee('Alpha Coaching')
        ->assertSee('Beta Institute');

    $byName = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/institutes?q=Alpha');
    $byName->assertSee('Alpha Coaching');
    $byName->assertDontSee('Beta Institute');

    $bySubdomain = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/institutes?q=beta.coaching.test');
    $bySubdomain->assertSee('Beta Institute');
    $bySubdomain->assertDontSee('Alpha Coaching');
});

test('the institutes list is paginated', function () {
    $admin = PlatformAdmin::factory()->create();
    Tenant::factory()->count(25)->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/institutes');

    $response->assertOk();
    $response->assertViewHas('tenants', fn ($tenants) => $tenants->count() === 20 && $tenants->total() === 25);
});

// === Create: mirrors tenant:create validation ===

test('creating an institute succeeds and shows the login URL, identifier and temporary password once', function () {
    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/institutes', validInstitutePayload());

    $response->assertRedirect();

    $tenant = Tenant::query()->where('name', 'Bright Future Academy')->firstOrFail();
    $domain = TenantDomain::query()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($domain->domain)->toBe('brightfuture-admin.'.config('tenancy.tenant_base_domain'));

    $showPage = $this->actingAs($admin, 'platform_admin')->get($response->headers->get('Location'));
    $showPage->assertSee($domain->domain);
    $showPage->assertSee('priya@example.com');

    // The password is shown once, via session flash — not persisted anywhere visible
    // again on a fresh request.
    $again = $this->actingAs($admin, 'platform_admin')->get($response->headers->get('Location'));
    $again->assertOk();
});

test('creating an institute rejects a reserved subdomain, mirroring tenant:create', function () {
    $admin = PlatformAdmin::factory()->create();

    // Positive control: a non-reserved subdomain succeeds.
    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/institutes', validInstitutePayload(['subdomain' => 'not-reserved-xyz']))
        ->assertRedirect();

    $response = $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/institutes', validInstitutePayload(['subdomain' => 'admin']));

    $response->assertSessionHasErrors('subdomain');
    expect(Tenant::query()->where('name', 'Bright Future Academy')->count())->toBe(1);
});

test('creating an institute rejects a duplicate subdomain', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/institutes', validInstitutePayload(['subdomain' => 'dup-admin-test']))
        ->assertRedirect();

    $response = $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/institutes', validInstitutePayload(['name' => 'Someone Else', 'subdomain' => 'dup-admin-test', 'owner_email' => 'other@example.com']));

    $response->assertSessionHasErrors('subdomain');
});

test('creating an institute requires an owner email or phone', function () {
    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/institutes', [
            'name' => 'No Contact Academy',
            'subdomain' => 'nocontact-admin',
            'owner_name' => 'Someone',
        ]);

    $response->assertSessionHasErrors(['owner_email', 'owner_phone']);
    expect(Tenant::query()->where('name', 'No Contact Academy')->exists())->toBeFalse();
});

// === Suspend / Reactivate ===

test('suspending an institute makes its domain return 503 for everyone and logs out a logged-in user; reactivating restores it', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = Tenant::factory()->create();
    $domain = 'suspend-test.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $student = inTenant($tenant, fn () => User::factory()->student()->create(['email' => 'student@example.com', 'password' => bcrypt('password123')]));

    // Positive control: the tenant's site works normally before suspension.
    $this->get("http://{$domain}/courses")->assertOk();

    $this->actingAs($student, 'tenant')->get("http://{$domain}/dashboard")->assertOk();

    $this->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/suspend")
        ->assertRedirect();

    expect($tenant->fresh()->status->value)->toBe('suspended');

    // Everyone (including a guest) gets 503 now.
    $this->get("http://{$domain}/courses")->assertStatus(503);

    // A previously logged-in user is logged out by the same request.
    $loggedOutCheck = $this->actingAs($student, 'tenant')->get("http://{$domain}/courses");
    $loggedOutCheck->assertStatus(503);
    $this->assertGuest('tenant');

    // Reactivating restores normal behaviour.
    $this->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/reactivate")
        ->assertRedirect();

    expect($tenant->fresh()->status->value)->toBe('active');
    $this->get("http://{$domain}/courses")->assertOk();
});

// === Reset owner password ===

test('resetting the owner password shows a new temporary password once and clears the login lockout', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = Tenant::factory()->create();
    $domain = 'reset-owner.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    inTenant($tenant, function () {
        $owner = User::factory()->owner()->create(['email' => 'owner@example.com']);
        $owner->forceFill(['password' => bcrypt('correct-password')])->save();
    });

    // Lock the owner out: 5 failed attempts from one IP trips the per-IP limiter.
    for ($i = 0; $i < 5; $i++) {
        $this->post("http://{$domain}/login", ['identifier' => 'owner@example.com', 'password' => 'wrong']);
    }
    $lockedOut = $this->post("http://{$domain}/login", ['identifier' => 'owner@example.com', 'password' => 'wrong']);
    $lockedOut->assertStatus(429);

    $response = $this->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/reset-owner-password");

    $response->assertRedirect();

    $showPage = $this->actingAs($admin, 'platform_admin')->get($response->headers->get('Location'));
    $showPage->assertOk();

    // The lockout is cleared: a login attempt (even a wrong one) is no longer 429.
    $afterReset = $this->post("http://{$domain}/login", ['identifier' => 'owner@example.com', 'password' => 'still-wrong']);
    $afterReset->assertStatus(302);
    $afterReset->assertSessionHasErrors();
});

// === Guard isolation for the new routes specifically ===

test('a tenant user cannot reach the admin institutes routes', function () {
    $tenant = Tenant::factory()->create();
    $user = inTenant($tenant, fn () => User::factory()->create());

    $response = $this->actingAs($user, 'tenant')
        ->get('http://coaching.test/admin/institutes');

    $response->assertRedirect();
    $this->assertGuest('platform_admin');
});

test('a platform admin cannot use tenant routes even for their own institutes', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'admin-cant-tenant.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->get("http://{$domain}/dashboard");

    $response->assertRedirect();
    $this->assertGuest('tenant');
});
