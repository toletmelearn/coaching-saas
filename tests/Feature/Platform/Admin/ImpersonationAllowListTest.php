<?php

use App\Models\Course;
use App\Models\LiveClass;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Batch 1 (G): while impersonating, a GET is allowed only when it is on an allow-list of safe
 * routes. A new or unlisted GET must be refused (fail closed), not allowed by default.
 */
function b1ImpersonationOwner(): array
{
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'allowlist.coaching.test', 'type' => 'subdomain', 'is_primary' => true]);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    Route::get('/b1-probe', fn () => 'probe ok')->middleware('web');

    return [$owner, 'http://allowlist.coaching.test', $tenant];
}

test('an unlisted GET is refused while impersonating (fail closed)', function () {
    [$owner, $origin] = b1ImpersonationOwner();

    $this->actingAs($owner, 'tenant')
        ->withSession(['impersonation' => ['admin_id' => 1, 'started_at' => now()->timestamp]])
        ->get("{$origin}/b1-probe")
        ->assertForbidden();
});

test('the credentials-sheet PAGE (which shows plaintext temporary passwords) is refused while impersonating, not only its download', function () {
    [$owner, $origin] = b1ImpersonationOwner();

    $this->actingAs($owner, 'tenant')
        ->withSession(['impersonation' => ['admin_id' => 1, 'started_at' => now()->timestamp]])
        ->get("{$origin}/users/import/sheet/not-a-real-token")
        ->assertForbidden();
});

test('the credentials-sheet download and clear routes are refused while impersonating', function () {
    [$owner, $origin] = b1ImpersonationOwner();

    $this->actingAs($owner, 'tenant')
        ->withSession(['impersonation' => ['admin_id' => 1, 'started_at' => now()->timestamp]])
        ->get("{$origin}/users/import/sheet/not-a-real-token/download")
        ->assertForbidden();

    freshRequestCycle();

    $this->actingAs($owner, 'tenant')
        ->withSession(['impersonation' => ['admin_id' => 1, 'started_at' => now()->timestamp]])
        ->post("{$origin}/users/import/sheet/not-a-real-token/clear")
        ->assertForbidden();
});

test('the live-class join route is refused while impersonating (it writes an attendance row)', function () {
    config(['coaching.live_classes_enabled' => true]);
    [$owner, $origin, $tenant] = b1ImpersonationOwner();

    $liveClass = inTenant($tenant, function () use ($owner) {
        $course = Course::factory()->published()->create();
        $class = new LiveClass;
        $class->fill(['course_id' => $course->id, 'title' => 'Join probe', 'starts_at' => now()->addHour()]);
        $class->forceFill(['status' => 'scheduled', 'created_by' => $owner->id]);
        $class->save();

        return $class->fresh();
    });

    $this->actingAs($owner, 'tenant')
        ->withSession(['impersonation' => ['admin_id' => 1, 'started_at' => now()->timestamp]])
        ->get("{$origin}/live-classes/{$liveClass->id}/join")
        ->assertForbidden();
});

test('positive control: logout is still allowed from an impersonated session', function () {
    [$owner, $origin] = b1ImpersonationOwner();

    $this->actingAs($owner, 'tenant')
        ->withSession(['impersonation' => ['admin_id' => 1, 'started_at' => now()->timestamp]])
        ->post("{$origin}/logout")
        ->assertRedirect();
});

test('positive control: the same unlisted GET works for a normal owner session', function () {
    [$owner, $origin] = b1ImpersonationOwner();

    $this->actingAs($owner, 'tenant')
        ->get("{$origin}/b1-probe")
        ->assertOk();
});

test('positive control: the dashboard (a listed safe GET) loads while impersonating', function () {
    [$owner, $origin] = b1ImpersonationOwner();

    $this->actingAs($owner, 'tenant')
        ->withSession(['impersonation' => ['admin_id' => 1, 'started_at' => now()->timestamp]])
        ->get("{$origin}/dashboard")
        ->assertOk();
});
