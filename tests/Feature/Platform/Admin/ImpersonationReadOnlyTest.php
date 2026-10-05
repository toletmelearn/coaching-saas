<?php

use App\Models\AdminAuditLog;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

/**
 * An impersonated owner session is read-only. Every non-GET request is refused with 403 except
 * POST /logout and POST /impersonation/exit. Student-data export and credentials-sheet download
 * GETs are refused too. Every refused attempt, the exit and the expiry are written to the audit log.
 */
function p161ImpersonatedOwner(TestCase $t): array
{
    $tenant = Tenant::factory()->create(['name' => 'Read Only Institute']);
    $tenant->domains()->create(['domain' => 'readonly.coaching.test', 'type' => 'subdomain', 'is_primary' => true]);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $student = inTenant($tenant, fn () => User::factory()->student()->create(['name' => 'Read Only Student']));

    $admin = PlatformAdmin::factory()->create();
    $mint = $t->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/login-as");
    $mint->assertRedirect();

    $t->get((string) $mint->headers->get('Location'))->assertRedirect('/dashboard');

    return [$tenant, $owner, $student, $admin];
}

test('an impersonated session refuses every write with 403, but a normal owner session can still write (positive control)', function () {
    [$tenant, $owner, $student] = p161ImpersonatedOwner($this);
    $origin = 'http://readonly.coaching.test';

    $this->get("{$origin}/dashboard")->assertSee(__('impersonation.exit'), false);

    $this->post("{$origin}/manage/getting-started/dismiss")->assertForbidden();
    $this->patch("{$origin}/users/{$student->id}", ['name' => 'Changed By Admin'])->assertForbidden();
    $this->delete("{$origin}/manage/students/{$student->id}/data", ['confirmation' => 'Read Only Student'])->assertForbidden();

    freshRequestCycle();
    $this->flushSession();
    $this->actingAs($owner, 'tenant')
        ->post("{$origin}/manage/getting-started/dismiss")
        ->assertRedirect('/dashboard');

    inTenant($tenant, fn () => expect(User::find($student->id)->name)->toBe('Read Only Student'));
});

test('student-data export and credentials-sheet download GETs are refused while impersonating (positive control: a normal owner gets the export)', function () {
    [$tenant, $owner, $student] = p161ImpersonatedOwner($this);
    $origin = 'http://readonly.coaching.test';

    $this->get("{$origin}/dashboard")->assertSee(__('impersonation.exit'), false);
    $this->get("{$origin}/manage/students/{$student->id}/data/export")->assertForbidden();
    $this->get("{$origin}/users/import/sheet/not-a-real-token/download")->assertForbidden();

    freshRequestCycle();
    $this->flushSession();
    $this->actingAs($owner, 'tenant')
        ->get("{$origin}/manage/students/{$student->id}/data/export")
        ->assertOk();
});

test('logout and the exit button are the only writes an impersonated session may make, and every refused attempt is audited (positive control: the exit is audited)', function () {
    [$tenant, $owner, $student, $admin] = p161ImpersonatedOwner($this);
    $origin = 'http://readonly.coaching.test';

    $this->post("{$origin}/manage/getting-started/dismiss")->assertForbidden();

    $blocked = AdminAuditLog::where('action', 'impersonation_blocked')->where('admin_id', $admin->id)->count();
    expect($blocked)->toBe(1);

    $this->post("{$origin}/impersonation/exit")->assertRedirect("{$origin}/login");

    expect(AdminAuditLog::where('action', 'impersonation_exit')->where('admin_id', $admin->id)->count())->toBe(1);
});

test('POST /logout is allowed from an impersonated session, not refused (positive control: a write on the same session is refused)', function () {
    [$tenant, $owner, $student] = p161ImpersonatedOwner($this);
    $origin = 'http://readonly.coaching.test';

    $this->post("{$origin}/manage/getting-started/dismiss")->assertForbidden();

    $this->post("{$origin}/logout")->assertRedirect();
    expect($this->app['auth']->guard('tenant')->check())->toBeFalse();
});
