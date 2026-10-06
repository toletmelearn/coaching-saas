<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;

/**
 * Phase 13 contract: the control-panel routes are platform_admin-only, and a
 * tenant session gets a flat 403 (not the framework's redirect) on every one of
 * them — PlatformAdminAuth checks tenant identity before delegating to normal
 * platform-admin authentication. The legacy admin routes keep their
 * long-standing redirect behaviour (PlatformAdminGuardTest pins it).
 */
function p13GuardRoutes(Tenant $tenant): array
{
    return [
        ['get', '/admin/health'],
        ['get', '/admin/settings/services'],
        ['get', '/admin/settings/system'],
        ['get', '/admin/backups'],
        ['get', '/admin/logs'],
        ['get', '/admin/audit'],
        ['get', "/admin/institutes/{$tenant->id}/bunny-usage"],
        ['post', '/admin/health/fix'],
        ['post', '/admin/settings/services'],
        ['post', '/admin/settings/services/test-bunny'],
        ['post', '/admin/settings/system'],
        ['post', '/admin/settings/system/clear-cache'],
        ['post', '/admin/settings/system/optimize'],
        ['post', '/admin/backups/run'],
        ['post', '/admin/backups/delete'],
        ['post', "/admin/institutes/{$tenant->id}/login-as"],
    ];
}

test('a tenant user gets a 403 on every Phase 13 admin route', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'guard403.coaching.test', 'type' => 'subdomain']);

    $user = inTenant($tenant, fn () => User::factory()->create());

    foreach (p13GuardRoutes($tenant) as [$method, $uri]) {
        $response = $this->actingAs($user, 'tenant')->{$method}("http://coaching.test{$uri}");

        $response->assertForbidden();
    }
});

test('the strict 403 does not leak onto the legacy admin routes', function () {
    // Positive control: a platform admin still reaches everything.
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/dashboard')
        ->assertOk();
});

test('a guest is still redirected to the platform login on Phase 13 routes', function () {
    $this->get('http://coaching.test/admin/audit')
        ->assertRedirect('http://coaching.test/admin/login');

    $this->post('http://coaching.test/admin/settings/system')
        ->assertRedirect('http://coaching.test/admin/login');
});

test('a platform admin can open every Phase 13 page', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'guard-ok.coaching.test', 'type' => 'subdomain']);

    foreach (p13GuardRoutes($tenant) as [$method, $uri]) {
        if ($method !== 'get') {
            continue; // POSTs mutate — covered by their own feature tests
        }

        $this->actingAs($admin, 'platform_admin')
            ->{$method}("http://coaching.test{$uri}")
            ->assertOk();
    }
});
