<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

function revokedDeviceFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $student = inTenant($tenant, function () {
        $student = User::factory()->student()->create();
        UserDevice::create([
            'user_id' => $student->id,
            'device_id' => 'revokedrevokedrevokedrevokedrevokedrevokedrevokedrevokedreva',
            'label' => 'Chrome on Android',
        ])->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => 'replaced',
        ])->save();

        return $student;
    });

    return [$tenant, $domain, $student, 'revokedrevokedrevokedrevokedrevokedrevokedrevokedrevokedreva'];
}

function activeDeviceStudent(Tenant $tenant): array
{
    $deviceId = bin2hex(random_bytes(16));

    $student = inTenant($tenant, function () use ($deviceId) {
        $student = User::factory()->student()->create();
        UserDevice::create([
            'user_id' => $student->id,
            'device_id' => $deviceId,
            'label' => 'Chrome on Android',
        ])->forceFill(['last_seen_at' => now()])->save();

        return $student;
    });

    return [$student, $deviceId];
}

// === Revoked device is signed out on its next request ===

test('a revoked device is logged out and redirected to login with the message on an HTML request', function () {
    [$tenant, $domain, $student, $deviceId] = revokedDeviceFixture();

    $response = $this->actingAs($student, 'tenant')
        ->withCookie('device_id', $deviceId)
        ->get("http://{$domain}/dashboard");

    $response->assertRedirect("http://{$domain}/login");
    $this->assertGuest('tenant');
    $response->assertSessionHas('status');
});

test('a revoked device gets a 401 with code device_revoked on a JSON request', function () {
    [$tenant, $domain, $student, $deviceId] = revokedDeviceFixture();

    $response = $this->actingAs($student, 'tenant')
        ->withCookie('device_id', $deviceId)
        ->getJson("http://{$domain}/dashboard");

    $response->assertUnauthorized();
    $response->assertJson(['code' => 'device_revoked']);
});

test('a still-valid device is unaffected (positive control)', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    [$student, $deviceId] = activeDeviceStudent($tenant);

    $this->actingAs($student, 'tenant')
        ->withCookie('device_id', $deviceId)
        ->get("http://{$domain}/dashboard")
        ->assertOk();
});

test('a revoked device is signed out on the lesson page', function () {
    [$tenant, $domain, $student, $deviceId] = revokedDeviceFixture();
    $lesson = inTenant($tenant, function () use ($student) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $lesson;
    });
    $course = inTenant($tenant, fn () => $lesson->course);

    $this->actingAs($student, 'tenant')
        ->withCookie('device_id', $deviceId)
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")
        ->assertRedirect("http://{$domain}/login");
});

test('a revoked device is signed out on the progress heartbeat endpoint', function () {
    [$tenant, $domain, $student, $deviceId] = revokedDeviceFixture();
    $lesson = inTenant($tenant, function () use ($student) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $lesson;
    });

    $this->actingAs($student, 'tenant')
        ->withCookie('device_id', $deviceId)
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", ['position' => 1, 'duration' => 10, 'played' => 1])
        ->assertUnauthorized();
});

test('a revoked device is signed out on the attachment download endpoint', function () {
    [$tenant, $domain, $student, $deviceId] = revokedDeviceFixture();
    [$course, $lesson, $attachment] = inTenant($tenant, function () use ($student) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        $attachment = LessonAttachment::factory()->for($lesson)->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$course, $lesson, $attachment];
    });

    $this->actingAs($student, 'tenant')
        ->withCookie('device_id', $deviceId)
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}/attachments/{$attachment->id}")
        ->assertRedirect("http://{$domain}/login");
});

test('a revoked device is signed out on the fake video stream endpoint', function () {
    [$tenant, $domain, $student, $deviceId] = revokedDeviceFixture();
    [$course, $video] = inTenant($tenant, function () use ($student) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        $video = LessonVideo::factory()->for($lesson)->ready()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$course, $video];
    });

    URL::forceRootUrl("http://{$domain}");
    $url = URL::temporarySignedRoute('lesson-videos.stream', now()->addMinutes(10), ['lessonVideo' => $video->id]);
    URL::forceRootUrl((string) config('app.url'));

    $this->actingAs($student, 'tenant')
        ->withCookie('device_id', $deviceId)
        ->get($url)
        ->assertRedirect("http://{$domain}/login");
});

// === Pre-existing session with no cookie ===

test('an authenticated session with no device cookie gets registered on its next request', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $response = $this->actingAs($student, 'tenant')->get("http://{$domain}/dashboard");
    $response->assertOk();

    inTenant($tenant, fn () => expect(UserDevice::where('user_id', $student->id)->count())->toBe(1));

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'device_id');
    expect($cookie)->not->toBeNull();
});

// === last_seen_at throttling ===

test('last_seen_at is updated at most once per minute', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    [$student, $deviceId] = activeDeviceStudent($tenant);

    $before = inTenant($tenant, fn () => UserDevice::where('user_id', $student->id)->first()->last_seen_at);

    $this->travel(10)->seconds();
    $this->actingAs($student, 'tenant')->withCookie('device_id', $deviceId)->get("http://{$domain}/dashboard");

    $afterQuickRequest = inTenant($tenant, fn () => UserDevice::where('user_id', $student->id)->first()->last_seen_at);
    expect($afterQuickRequest->equalTo($before))->toBeTrue();

    $this->travel(61)->seconds();
    freshRequestCycle();
    $this->actingAs($student, 'tenant')->withCookie('device_id', $deviceId)->get("http://{$domain}/dashboard");

    $afterSlowRequest = inTenant($tenant, fn () => UserDevice::where('user_id', $student->id)->first()->last_seen_at);
    expect($afterSlowRequest->greaterThan($before))->toBeTrue();
});

// === Query-count budget ===

test('device.limit touches user_devices with at most one SELECT and one throttled UPDATE per request', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    [$student, $deviceId] = activeDeviceStudent($tenant);

    // The device row is already registered (activeDeviceStudent) but its last_seen_at is
    // old enough that the throttled write should fire on this request too — exercising
    // both bounds (the read and the conditional write) in the one request being measured.
    inTenant($tenant, fn () => UserDevice::where('user_id', $student->id)->update(['last_seen_at' => now()->subMinutes(5)]));

    DB::enableQueryLog();
    $this->actingAs($student, 'tenant')->withCookie('device_id', $deviceId)->get("http://{$domain}/dashboard")->assertOk();
    $deviceQueries = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'user_devices'));
    DB::disableQueryLog();

    $selects = $deviceQueries->filter(fn ($entry) => str_starts_with(trim($entry['query']), 'select'));
    $updates = $deviceQueries->filter(fn ($entry) => str_starts_with(trim($entry['query']), 'update'));

    // Positive control: device.limit must actually have looked the row up — a budget of
    // "at most one" is only meaningful once at least one real query is proven to happen.
    expect($selects->count())->toBeGreaterThan(0);

    expect($selects->count())->toBeLessThanOrEqual(1);
    expect($updates->count())->toBeLessThanOrEqual(1);
});

// === Login-page notice for a revoked-but-unauthenticated device ===

test('the login page shows the device-revoked notice once for a revoked, unauthenticated device', function () {
    [$tenant, $domain, , $deviceId] = revokedDeviceFixture();

    $first = $this->withCookie('device_id', $deviceId)->get("http://{$domain}/login");
    $first->assertOk();
    $first->assertSee(__('auth.device_revoked'));

    inTenant($tenant, fn () => expect(UserDevice::where('device_id', $deviceId)->first()->notified_at)->not->toBeNull());

    $second = $this->withCookie('device_id', $deviceId)->get("http://{$domain}/login");
    $second->assertOk();
    $second->assertDontSee(__('auth.device_revoked'));
});
