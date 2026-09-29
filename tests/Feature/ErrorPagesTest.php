<?php

use App\Models\Tenant;
use App\Models\User;

test('a staff user hitting a forbidden action sees the translated 403 page', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    // Positive control: staff CAN reach the management course list (proves the request
    // itself reaches a real route, not a routing-level 404, before hitting the 403 case).
    $this->actingAs($staff, 'tenant')
        ->get("http://{$domain}/manage/courses")
        ->assertOk();

    $response = $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/manage/courses", [
            'title' => 'Should not be allowed',
        ]);

    $response->assertForbidden();
    $response->assertSee(__('errors.403.heading'));
    $response->assertSee(__('errors.403.message'));
});
