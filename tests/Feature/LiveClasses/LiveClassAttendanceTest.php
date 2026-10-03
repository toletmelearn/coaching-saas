<?php

use App\Models\LiveClassAttendance;
use Tests\Support\LiveClassFixtures;

// === Join opens a row; heartbeats move it ===

test('the first heartbeat advances last_seen_at and credits the elapsed seconds', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertRedirect();

    $this->travel(30)->seconds();

    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/live-classes/{$class->id}/heartbeat")
        ->assertNoContent();

    $row = inTenant($f['tenant'], fn () => LiveClassAttendance::firstOrFail());

    expect((int) $row->duration_seconds)->toBe(30)
        ->and($row->last_seen_at->getTimestamp())->toBe(now()->getTimestamp());
});

test('a single heartbeat can credit at most 60 seconds', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertRedirect();

    $this->travel(30)->seconds();
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/live-classes/{$class->id}/heartbeat")
        ->assertNoContent();

    // Five silent minutes, then one beacon: only the 60-second cap counts, not 300.
    $this->travel(5)->minutes();
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/live-classes/{$class->id}/heartbeat")
        ->assertNoContent();

    $row = inTenant($f['tenant'], fn () => LiveClassAttendance::firstOrFail());
    expect((int) $row->duration_seconds)->toBe(90);
});

test('heartbeat is rate limited to 2 per minute per user per class', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    $url = "http://{$f['domain']}/live-classes/{$class->id}/heartbeat";

    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertRedirect();

    $this->actingAs($f['student'], 'tenant')->post($url)->assertNoContent();
    $this->actingAs($f['student'], 'tenant')->post($url)->assertNoContent();
    $this->actingAs($f['student'], 'tenant')->post($url)->assertStatus(429);
});

test('client-supplied timestamps and durations in the heartbeat body are ignored', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertRedirect();

    $joinedAt = inTenant($f['tenant'], fn () => LiveClassAttendance::firstOrFail())
        ->joined_at->format('Y-m-d H:i:s');

    $this->travel(10)->seconds();

    $this->actingAs($f['student'], 'tenant')->postJson(
        "http://{$f['domain']}/live-classes/{$class->id}/heartbeat",
        [
            'joined_at' => '2000-01-01 00:00:00',
            'last_seen_at' => '2000-01-01 00:00:00',
            'left_at' => '2000-01-01 00:00:00',
            'duration_seconds' => 999999,
        ]
    )->assertNoContent();

    $row = inTenant($f['tenant'], fn () => LiveClassAttendance::firstOrFail());

    expect((int) $row->duration_seconds)->toBe(10)
        ->and($row->joined_at->format('Y-m-d H:i:s'))->toBe($joinedAt)
        ->and($row->left_at)->toBeNull()
        ->and($row->last_seen_at->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
});

test('a client hammering the heartbeat endpoint cannot inflate duration beyond twice the real elapsed time', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    $url = "http://{$f['domain']}/live-classes/{$class->id}/heartbeat";

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertRedirect();

    // Ten forgeries two real seconds apart. Rate limiting and the per-beat cap
    // both constrain what the server will ever credit — we only assert the
    // end-to-end property the security requirement asks for. Statuses are
    // deliberately ignored: a 429 here is the defence working, not a failure.
    for ($i = 0; $i < 10; $i++) {
        $this->travel(2)->seconds();
        $this->actingAs($f['student'], 'tenant')->postJson($url, [
            'duration_seconds' => 999999,
            'last_seen_at' => '2000-01-01 00:00:00',
        ]);
    }

    $row = inTenant($f['tenant'], fn () => LiveClassAttendance::firstOrFail());
    $elapsed = now()->getTimestamp() - $row->joined_at->getTimestamp();

    expect((int) $row->duration_seconds)->toBeGreaterThan(0)
        ->and((int) $row->duration_seconds)->toBeLessThanOrEqual(2 * $elapsed);
});

// === Sessions ===

test('an instant rejoin resumes the open row, but rejoining after a gap opens a second one', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    $join = "http://{$f['domain']}/live-classes/{$class->id}/join";

    $this->actingAs($f['student'], 'tenant')->get($join)->assertRedirect();

    // A refresh / double-click ten seconds later is the same session
    $this->travel(10)->seconds();
    $this->actingAs($f['student'], 'tenant')->get($join)->assertRedirect();
    expect(inTenant($f['tenant'], fn () => LiveClassAttendance::count()))->toBe(1);

    // Leaving for two minutes (heartbeats stopped) and coming back is a new
    // session — the report must be able to tell one long stay from two short ones
    $this->travel(2)->minutes();
    $this->actingAs($f['student'], 'tenant')->get($join)->assertRedirect();

    $rows = inTenant($f['tenant'], fn () => LiveClassAttendance::orderBy('joined_at')->get());
    expect($rows)->toHaveCount(2)
        ->and($rows[1]->joined_at->getTimestamp())->toBeGreaterThan($rows[0]->joined_at->getTimestamp());
});

test('live-classes:close-stale-attendance closes rows whose heartbeats went quiet five minutes ago', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertRedirect();

    $this->travel(30)->seconds();
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/live-classes/{$class->id}/heartbeat")
        ->assertNoContent();

    $this->travel(6)->minutes();

    $this->artisan('live-classes:close-stale-attendance')->assertSuccessful();

    $row = inTenant($f['tenant'], fn () => LiveClassAttendance::firstOrFail());
    expect($row->left_at)->not->toBeNull()
        ->and($row->left_at->format('Y-m-d H:i:s'))->toBe($row->last_seen_at->format('Y-m-d H:i:s'))
        ->and((int) $row->duration_seconds)->toBe(30);

    // The student's beacon resumes later: the closed row stays frozen and a
    // second session opens instead of resurrecting the first
    $this->travel(1)->minutes();
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/live-classes/{$class->id}/heartbeat")
        ->assertNoContent();

    $rows = inTenant($f['tenant'], fn () => LiveClassAttendance::orderBy('joined_at')->get());
    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->duration_seconds)->toBe(30)
        ->and($rows[0]->left_at)->not->toBeNull();
});

// === Authorisation ===

test('a same-tenant student who is not enrolled cannot heartbeat', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    $url = "http://{$f['domain']}/live-classes/{$class->id}/heartbeat";

    // Positive control: the enrolled student's heartbeat is accepted
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertRedirect();
    $this->actingAs($f['student'], 'tenant')->post($url)->assertNoContent();

    // Negative: logged in, same tenant, no enrolment
    $this->actingAs($f['outsider'], 'tenant')->post($url)->assertForbidden();
});

test('heartbeat is refused when the class is not open for business', function () {
    $f = LiveClassFixtures::setup();

    $live = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Live class',
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);
    $upcoming = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Upcoming class',
        'starts_at' => now()->addHour(),
    ]);
    $ended = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Ended class',
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
        'status' => 'ended',
    ]);
    $cancelled = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Cancelled class',
        'starts_at' => now()->addMinutes(5),
        'status' => 'cancelled',
    ]);

    // Positive control: the open class accepts a heartbeat
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$live->id}/join")
        ->assertRedirect();
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/live-classes/{$live->id}/heartbeat")
        ->assertNoContent();

    // Every non-open state is a flat 403 — the server never keeps counting
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/live-classes/{$upcoming->id}/heartbeat")
        ->assertForbidden();
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/live-classes/{$ended->id}/heartbeat")
        ->assertForbidden();
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/live-classes/{$cancelled->id}/heartbeat")
        ->assertForbidden();

    expect(inTenant($f['tenant'], fn () => LiveClassAttendance::count()))->toBe(1);
});
