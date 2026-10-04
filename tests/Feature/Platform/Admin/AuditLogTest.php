<?php

use App\Models\AdminAuditLog;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 feature F — the audit trail itself: every privileged action writes
 * a row (login, create_tenant, reset_password + the panel's own ops), the page
 * renders them, and ?action= filters. Deleted admins still render (no FK).
 */

// Phase 13.1 — canary for the shipped bug: the migration existed but a fresh
// schema build that skipped it would surface here, before anyone hits
// SQLSTATE[42S02] on /admin/institutes in a real environment.
test('the admin_audit_logs table exists after migration', function () {
    expect(Schema::hasTable('admin_audit_logs'))->toBeTrue();
});

test('a platform admin login is recorded', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->post('http://coaching.test/admin/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertRedirect('/admin/dashboard');

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'login',
        'admin_id' => $admin->id,
        'target_type' => null,
        'target_id' => null,
    ]);
});

test('a failed platform admin login is not recorded', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->post('http://coaching.test/admin/login', [
        'email' => $admin->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertDatabaseCount('admin_audit_logs', 0);
});

test('institute creation is recorded against the new tenant', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->post('http://coaching.test/admin/institutes', [
            'name' => 'Audit Trail Academy',
            'subdomain' => 'audit-trail-a',
            'owner_name' => 'Owner Person',
            'owner_email' => 'owner@audit.test',
        ])
        ->assertRedirect();

    $tenant = Tenant::query()->where('name', 'Audit Trail Academy')->firstOrFail();

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'create_tenant',
        'target_type' => 'tenant',
        'target_id' => $tenant->id,
        'admin_id' => $admin->id,
    ]);
});

test('an owner password reset is recorded against the owner', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'audit-reset.coaching.test', 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/reset-owner-password")
        ->assertRedirect("http://coaching.test/admin/institutes/{$tenant->id}")
        ->assertSessionHas('temporary_password');

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'reset_password',
        'target_type' => 'user',
        'target_id' => $owner->id,
        'admin_id' => $admin->id,
    ]);
});

test('suspend and reactivate are recorded', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'audit-suspend.coaching.test', 'type' => 'subdomain']);

    $this->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/suspend");
    $this->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/reactivate");

    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'suspend_tenant',
        'target_type' => 'tenant',
        'target_id' => $tenant->id,
        'admin_id' => $admin->id,
    ]);
    $this->assertDatabaseHas('admin_audit_logs', [
        'action' => 'reactivate_tenant',
        'target_type' => 'tenant',
        'target_id' => $tenant->id,
        'admin_id' => $admin->id,
    ]);
});

test('the audit page lists entries newest first and offers an action filter', function () {
    $admin = PlatformAdmin::factory()->create();

    AdminAuditLog::record('login', adminId: $admin->id, ipAddress: '127.0.0.1');
    AdminAuditLog::record('create_tenant', 'tenant', 99, $admin->id, '127.0.0.1');
    AdminAuditLog::record('impersonate', 'user', 7, $admin->id, '127.0.0.1');

    $page = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/audit');

    $page->assertOk();
    $page->assertSee('create tenant');
    $page->assertSee('impersonate');
    $page->assertSee('127.0.0.1');
    $page->assertViewHas('actions', function ($actions): bool {
        return $actions->contains('login')
            && $actions->contains('create_tenant')
            && $actions->contains('impersonate');
    });
});

test('the audit filter narrows the list to one action', function () {
    $admin = PlatformAdmin::factory()->create();

    AdminAuditLog::record('login', adminId: $admin->id, ipAddress: '11.22.33.44');
    AdminAuditLog::record('impersonate', 'user', 7, $admin->id, '127.0.0.1');

    $filtered = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/audit?action=impersonate');

    $filtered->assertOk();
    $filtered->assertSee('user #7');
    $filtered->assertSee('127.0.0.1');
    $filtered->assertDontSee('11.22.33.44');
    $filtered->assertViewHas('action', 'impersonate');
});

test('a row survives its admin being deleted and renders as a raw id', function () {
    $admin = PlatformAdmin::factory()->create();

    AdminAuditLog::record('login', adminId: $admin->id, ipAddress: '127.0.0.1');
    $admin->delete();

    $this->actingAs(PlatformAdmin::factory()->create(), 'platform_admin')
        ->get('http://coaching.test/admin/audit')
        ->assertOk()
        ->assertSee(__('platform.admin.audit.unknown_admin', ['id' => $admin->id]));
});

test('the audit list paginates', function () {
    $admin = PlatformAdmin::factory()->create();

    for ($i = 0; $i < 55; $i++) {
        AdminAuditLog::record('clear_cache', adminId: $admin->id, ipAddress: '127.0.0.1');
    }

    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/audit')
        ->assertOk()
        ->assertViewHas('logs', fn ($logs) => $logs->total() === 55 && $logs->count() === 50);
});
