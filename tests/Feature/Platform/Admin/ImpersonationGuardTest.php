<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Tests\Support\ConsentFixtures;
use Tests\TestCase;

/**
 * While an impersonated session is active, the owner cannot change their password or erase a
 * student. A normal owner session still can. Uses its own helpers so this file runs alone.
 */
function p16gImpersonatedOwner(TestCase $t): array
{
    $tenant = Tenant::factory()->create(['name' => 'Guard Institute']);
    $tenant->domains()->create(['domain' => 'guard.coaching.test', 'type' => 'subdomain', 'is_primary' => true]);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $student = inTenant($tenant, fn () => User::factory()->student()->create(['name' => 'Guarded Student']));

    $admin = PlatformAdmin::factory()->create();
    $mint = $t->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/institutes/{$tenant->id}/login-as");
    $mint->assertRedirect();

    $t->get((string) $mint->headers->get('Location'))->assertRedirect('/dashboard');

    return [$tenant, $owner, $student];
}

test('an impersonating session cannot change the password (403), while a normal owner session can (positive control)', function () {
    [$tenant, $owner] = p16gImpersonatedOwner($this);
    $origin = 'http://guard.coaching.test';

    $this->get("{$origin}/dashboard")->assertSee(__('impersonation.exit'), false);
    $this->post("{$origin}/auth/change-password", [
        'password' => 'brand-new-password-1',
        'password_confirmation' => 'brand-new-password-1',
    ])->assertForbidden();

    freshRequestCycle();
    $this->flushSession();

    $this->actingAs($owner, 'tenant')
        ->post("{$origin}/auth/change-password", ['password' => 'x', 'password_confirmation' => 'y'])
        ->assertStatus(302);
});

test('an impersonating session cannot erase a student (403), while a normal owner session can (positive control)', function () {
    [$tenant, $owner, $student] = p16gImpersonatedOwner($this);
    $origin = 'http://guard.coaching.test';
    $url = "{$origin}/manage/students/{$student->id}/data";

    $this->get("{$origin}/dashboard")->assertSee(__('impersonation.exit'), false);
    $this->delete($url, ['confirmation' => 'Guarded Student'])->assertForbidden();

    freshRequestCycle();
    $this->flushSession();

    $this->actingAs($owner, 'tenant')
        ->delete($url, ['confirmation' => 'Guarded Student'])
        ->assertRedirect();

    inTenant($tenant, fn () => expect(User::find($student->id)->name)->toBe(ConsentFixtures::ERASED_NAME));
});
