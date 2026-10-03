<?php

use App\Enums\LiveClassStatus;
use App\Models\LiveClassAttendance;
use App\Models\User;
use Tests\Support\LiveClassFixtures;

// === Joining ===

test('an enrolled student joining a live class is redirected to Jitsi with a server-minted JWT and an attendance row opens', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $response = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join");

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');

    // The URL is minted server-side against the JaaS app id, in the redirect —
    // never rendered into HTML that persists across requests.
    expect($location)->toStartWith('https://8x8.vc/test-jitsi-app-id/')
        ->and($location)->toContain($class->jitsi_room_name);

    $payload = LiveClassFixtures::joinJwt($location);
    expect($payload['context']['user']['name'])->toBe($f['student']->name)
        ->and($payload['context']['user']['email'])->toBe($f['student']->email)
        ->and($payload['aud'])->toBe('test-jitsi-app-id')
        ->and($payload['iss'])->toBe('test-jitsi-app-id');

    // Attendance opened with server-derived values
    $row = inTenant($f['tenant'], fn () => LiveClassAttendance::firstOrFail());
    expect((int) $row->live_class_id)->toBe($class->id)
        ->and((int) $row->user_id)->toBe($f['student']->id)
        ->and((int) $row->duration_seconds)->toBe(0)
        ->and($row->left_at)->toBeNull()
        ->and(abs($row->joined_at->diffInSeconds(now())))->toBeLessThanOrEqual(5);
});

test('an enrolled student cannot join more than 15 minutes before the start', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->addMinutes(45),
    ]);

    $show = "http://{$f['domain']}/live-classes/{$class->id}";

    $this->actingAs($f['student'], 'tenant')
        ->from($show)
        ->get("{$show}/join")
        ->assertRedirect($show)
        ->assertSessionHasErrors(['live_class' => __('live_classes.not_yet_open')]);
});

test('an enrolled student may join inside the 15-minute pre-open window', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->addMinutes(10),
    ]);

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertRedirect();

    expect(inTenant($f['tenant'], fn () => LiveClassAttendance::count()))->toBe(1);
});

test('an enrolled student cannot join a class that has ended', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
        'status' => 'live', // the class ends properly via live-classes:update-status
    ]);
    inTenant($f['tenant'], fn () => $class->forceFill(['status' => LiveClassStatus::Ended])->save());

    $show = "http://{$f['domain']}/live-classes/{$class->id}";

    $this->actingAs($f['student'], 'tenant')
        ->from($show)
        ->get("{$show}/join")
        ->assertRedirect($show)
        ->assertSessionHasErrors(['live_class' => __('live_classes.already_ended')]);

    expect(inTenant($f['tenant'], fn () => LiveClassAttendance::count()))->toBe(0);
});

test('an enrolled student cannot join a cancelled class', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->addMinutes(5),
        'status' => 'cancelled',
    ]);

    $show = "http://{$f['domain']}/live-classes/{$class->id}";

    $this->actingAs($f['student'], 'tenant')
        ->from($show)
        ->get("{$show}/join")
        ->assertRedirect($show)
        ->assertSessionHasErrors(['live_class' => __('live_classes.cancelled')]);

    expect(inTenant($f['tenant'], fn () => LiveClassAttendance::count()))->toBe(0);
});

test('the owner can join their own class without an enrolment', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join");

    $response->assertRedirect();

    $row = inTenant($f['tenant'], fn () => LiveClassAttendance::firstOrFail());
    expect((int) $row->user_id)->toBe($f['owner']->id);
});

test('a guest hitting join is sent to the tenant login page', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'status' => 'live',
    ]);

    $this->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertRedirect("http://{$f['domain']}/login");
});

test('a student who must change their password is bounced to the password form before joining', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'status' => 'live',
    ]);

    $student = inTenant($f['tenant'], fn () => User::factory()->student()->mustChangePassword()->create());

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join");

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toContain('/auth/change-password');
});

test('the class page never exposes the Jitsi URL or the room name', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $page = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}");

    $page->assertOk()
        ->assertDontSee('8x8.vc')
        ->assertDontSee($class->jitsi_room_name);
});
