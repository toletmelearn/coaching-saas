<?php

use Tests\Support\LiveClassFixtures;

/**
 * Batch 2.1 — meeting_url field validation.
 *
 * Every test must fail on the old code because:
 *  - the migration adding the meeting_url column has not run (DB will reject any
 *    assertDatabaseHas that checks meeting_url), OR
 *  - the store/update action does not validate meeting_url (no validation errors are
 *    returned, so assertSessionHasErrors('meeting_url') fails).
 */

// ---- Allowed hosts accepted ----------------------------------------------------------------

test('2.1 allowed: all six listed hosts are accepted and meeting_url is stored', function (string $url) {
    $f = LiveClassFixtures::setup();
    $data = [
        'title' => 'Test class',
        'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
        'meeting_url' => $url,
    ];

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", $data)
        ->assertRedirect();

    // The column must exist and the URL must be stored.
    $this->assertDatabaseHas('live_classes', ['meeting_url' => $url]);
})->with([
    'Google Meet'       => ['https://meet.google.com/abc-defg-hij'],
    'zoom.us'           => ['https://zoom.us/j/123456789'],
    'teams'             => ['https://teams.microsoft.com/l/meetup-join/test'],
    'us02web.zoom.us'   => ['https://us02web.zoom.us/j/987654321'],
    'us04web.zoom.us'   => ['https://us04web.zoom.us/j/987654321'],
    'us05web.zoom.us'   => ['https://us05web.zoom.us/j/987654321'],
]);

test('2.1 allowed: null meeting_url is accepted (field is optional)', function () {
    $f = LiveClassFixtures::setup();
    $data = [
        'title' => 'No meeting URL',
        'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
    ];

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", $data)
        ->assertRedirect();

    // Column must exist and be null for the newly created row.
    $this->assertDatabaseHas('live_classes', ['title' => 'No meeting URL', 'meeting_url' => null]);
});

// ---- javascript: scheme rejected -----------------------------------------------------------

test('2.1 rejected: javascript: URL is refused with a meeting_url validation error', function () {
    $f = LiveClassFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", [
            'title' => 'Bad URL class',
            'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'meeting_url' => 'javascript:alert(document.cookie)',
        ])
        ->assertSessionHasErrors('meeting_url');

    // Positive control: a valid URL on the same request flow succeeds (tested via the
    // "allowed hosts" test above — confirmed no DB row for the hostile URL).
    $this->assertDatabaseMissing('live_classes', ['meeting_url' => 'javascript:alert(document.cookie)']);
});

// ---- http:// rejected -----------------------------------------------------------------------

test('2.1 rejected: http:// URL is refused (https required)', function () {
    $f = LiveClassFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", [
            'title' => 'HTTP class',
            'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'meeting_url' => 'http://zoom.us/j/123456789',
        ])
        ->assertSessionHasErrors('meeting_url');
});

// ---- unlisted host rejected ----------------------------------------------------------------

test('2.1 rejected: a host not on the allow-list is refused', function () {
    $f = LiveClassFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", [
            'title' => 'Evil URL class',
            'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'meeting_url' => 'https://evil.com/meeting/steal',
        ])
        ->assertSessionHasErrors('meeting_url');
});

// ---- data: scheme rejected -----------------------------------------------------------------

test('2.1 rejected: data: URL is refused', function () {
    $f = LiveClassFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", [
            'title' => 'Data URI class',
            'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'meeting_url' => 'data:text/html,<h1>xss</h1>',
        ])
        ->assertSessionHasErrors('meeting_url');
});

// ---- edit (update) also validates ----------------------------------------------------------

test('2.1 update: editing a class with a bad meeting_url is rejected', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->addHour(),
    ]);

    $this->actingAs($f['owner'], 'tenant')
        ->patch("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}", [
            'meeting_url' => 'https://notallowed.example.com/meeting',
        ])
        ->assertSessionHasErrors('meeting_url');
});

test('2.1 update: editing a class with a valid meeting_url is accepted', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'starts_at' => now()->addHour(),
    ]);
    $url = 'https://zoom.us/j/999888777';

    $this->actingAs($f['owner'], 'tenant')
        ->patch("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}", [
            'meeting_url' => $url,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'meeting_url' => $url]);
});
