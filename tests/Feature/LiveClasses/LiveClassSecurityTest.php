<?php

use App\Models\LiveClassAttendance;
use Illuminate\Support\Facades\Log;
use Tests\Support\LiveClassFixtures;

// === Cross-tenant ===

test('a student in another tenant cannot see, join or heartbeat a live class', function () {
    $fA = LiveClassFixtures::setup();
    $classA = LiveClassFixtures::liveClass($fA['course'], $fA['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    // Second tenant needs its own host: tenant_domains is unique per domain
    $fB = LiveClassFixtures::setup(['domain' => 'liveclasses-evil.coaching.test']);

    // Positive control: the same class is perfectly visible to its own tenant's student
    $this->actingAs($fA['student'], 'tenant')
        ->get("http://{$fA['domain']}/live-classes/{$classA->id}")
        ->assertOk();

    // Negative: tenant B's student poking at tenant A's class id
    $this->actingAs($fB['student'], 'tenant')
        ->get("http://{$fB['domain']}/live-classes/{$classA->id}")
        ->assertNotFound();

    $this->actingAs($fB['student'], 'tenant')
        ->get("http://{$fB['domain']}/live-classes/{$classA->id}/join")
        ->assertNotFound();

    $this->actingAs($fB['student'], 'tenant')
        ->post("http://{$fB['domain']}/live-classes/{$classA->id}/heartbeat")
        ->assertNotFound();

    // Nothing leaked on the other side of the border
    expect(inTenant($fA['tenant'], fn () => LiveClassAttendance::count()))->toBe(0);
});

// === Non-enrolled, same tenant ===

test('a same-tenant student without an enrolment gets 403 on the class page and on join', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    // Positive control: the enrolled student gets the real page
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertOk();

    // Negative: same tenant, logged in, no enrolment
    $this->actingAs($f['outsider'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}")
        ->assertForbidden();

    $this->actingAs($f['outsider'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join")
        ->assertForbidden();

    expect(inTenant($f['tenant'], fn () => LiveClassAttendance::count()))->toBe(0);
});

// === The JaaS secret never escapes ===

test('the JaaS app secret never appears in a response body, a redirect or captured logs', function () {
    $f = LiveClassFixtures::setup(); // sets services.jitsi.app_secret to the fixture value
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $captured = [];
    Log::listen(function ($event) use (&$captured) {
        $captured[] = $event->message.' '.json_encode($event->context);
    });

    // Positive control: the listener genuinely captures log calls made through the facade
    Log::info('phase-12-log-listener-sanity-check');
    expect($captured)->toContain('phase-12-log-listener-sanity-check []');

    $join = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join");
    $join->assertRedirect();

    $show = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}");
    $show->assertOk();

    $secret = 'test-jitsi-secret-abc123';

    expect((string) $join->headers->get('Location'))->not->toContain($secret)
        ->and($show->getContent())->not->toContain($secret)
        ->and(implode("\n", $captured))->not->toContain($secret);
});

// === Escaping: student names are user input ===

test('a hostile display name survives the JWT round-trip and cannot break the redirect URL', function () {
    $f = LiveClassFixtures::setup();
    $hostile = 'O\'Brien "X" <script>alert(1)</script> & Co';

    inTenant($f['tenant'], fn () => $f['student']->forceFill(['name' => $hostile])->save());

    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addHour(),
        'status' => 'live',
    ]);

    $response = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/live-classes/{$class->id}/join");

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');

    // The URL stays a well-formed https URL pointing at the JaaS host…
    expect(parse_url($location, PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url($location, PHP_URL_HOST))->toBe('8x8.vc')
        // …the raw script tag never appears outside the base64url JWT…
        ->and($location)->not->toContain('<script>')
        // …and the name survives the round-trip byte-for-byte once decoded
        ->and(LiveClassFixtures::joinJwt($location)['context']['user']['name'])->toBe($hostile);
});
