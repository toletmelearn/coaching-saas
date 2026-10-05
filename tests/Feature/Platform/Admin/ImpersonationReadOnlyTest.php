<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;

/**
 * An impersonated owner session is read-only: every non-GET request is refused with 403, except
 * the Exit button's POST to /impersonation/exit. A normal owner session can still write.
 */

function p161ImpersonatedOwner(\Tests\TestCase $t): array
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

    return [$tenant, $owner, $student];
}

test('an impersonated session refuses every write with 403, but a normal owner session can still write (positive control)', function () {
    [$tenant, $owner, $student] = p161ImpersonatedOwner($this);
    $origin = 'http://readonly.coaching.test';

    $this->get("{$origin}/dashboard")->assertSee(__('impersonation.exit'), false);

    $this->post("{$origin}/manage/getting-started/dismiss")->assertForbidden();
    $this->patch("{$origin}/users/{$student->id}", ['name' => 'Changed By Admin'])->assertForbidden();
    $this->delete("{$origin}/manage/students/{$student->id}/data", ['confirmation' => 'Read Only Student'])->assertForbidden();

    // The one write an impersonated session may make: ending the impersonation.
    $this->post("{$origin}/impersonation/exit")->assertRedirect("{$origin}/login");

    // Positive control: a normal owner session performs the same write without a 403.
    freshRequestCycle();
    $this->flushSession();
    $this->actingAs($owner, 'tenant')
        ->post("{$origin}/manage/getting-started/dismiss")
        ->assertRedirect('/dashboard');

    inTenant($tenant, fn () => expect(User::find($student->id)->name)->toBe('Read Only Student'));
});
