<?php

use App\Enums\LiveClassStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LiveClass;
use Tests\Support\LiveClassFixtures;

// === Scheduling: who may create, and with what ===

test('an owner can schedule a live class and every server-owned field comes out server-derived', function () {
    $f = LiveClassFixtures::setup();
    $startsAt = now()->addHour();

    $response = $this->actingAs($f['owner'], 'tenant')->post(
        "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes",
        [
            'title' => 'Live doubt session',
            'description' => 'Bring your questions',
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addHours(2)->format('Y-m-d H:i:s'),
        ]
    );

    $response->assertRedirect("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes");

    $class = inTenant($f['tenant'], fn () => LiveClass::firstOrFail());

    expect($class->title)->toBe('Live doubt session')
        ->and($class->status)->toBe(LiveClassStatus::Scheduled)
        ->and($class->starts_at->format('Y-m-d H:i:s'))->toBe($startsAt->format('Y-m-d H:i:s'))
        ->and((int) $class->course_id)->toBe($f['course']->id)
        ->and((int) $class->created_by)->toBe($f['owner']->id)
        ->and((int) $class->tenant_id)->toBe($f['tenant']->id)
        ->and($class->jitsi_room_name)->toMatch('/^[A-Za-z0-9]{32}-[0-9a-f]{8}$/');
});

test('staff with canManageCourses can schedule a live class', function () {
    $f = LiveClassFixtures::setup();

    $response = $this->actingAs($f['staff'], 'tenant')->post(
        "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes",
        ['title' => 'Staff-scheduled class', 'starts_at' => now()->addHour()->format('Y-m-d H:i:s')]
    );

    $response->assertRedirect("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes");

    $class = inTenant($f['tenant'], fn () => LiveClass::firstOrFail());
    expect((int) $class->created_by)->toBe($f['staff']->id);
});

test('a student cannot schedule a live class', function () {
    $f = LiveClassFixtures::setup();

    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes", [
            'title' => 'Student-made class',
            'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
        ])
        ->assertForbidden();
});

test('the store action validates the title (required, at most 150 characters)', function () {
    $f = LiveClassFixtures::setup();
    $url = "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes";

    $this->actingAs($f['owner'], 'tenant')->post($url, [
        'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
    ])->assertSessionHasErrors('title');

    $this->actingAs($f['owner'], 'tenant')->post($url, [
        'title' => str_repeat('a', 151),
        'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
    ])->assertSessionHasErrors('title');
});

test('the store action validates starts_at (required and not in the past)', function () {
    $f = LiveClassFixtures::setup();
    $url = "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes";

    $this->actingAs($f['owner'], 'tenant')->post($url, [
        'title' => 'No start time',
    ])->assertSessionHasErrors('starts_at');

    $this->actingAs($f['owner'], 'tenant')->post($url, [
        'title' => 'Yesterday',
        'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
    ])->assertSessionHasErrors('starts_at');
});

test('the store action requires ends_at to come after starts_at', function () {
    $f = LiveClassFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')->post(
        "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes",
        [
            'title' => 'Backwards window',
            'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addMinutes(30)->format('Y-m-d H:i:s'),
        ]
    )->assertSessionHasErrors('ends_at');
});

test('the store action rejects a lesson that belongs to a different course', function () {
    $f = LiveClassFixtures::setup();

    $foreignLesson = inTenant($f['tenant'], function () {
        $otherCourse = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($otherCourse)->create();

        return Lesson::factory()->for($chapter)->published()->create(['course_id' => $otherCourse->id]);
    });

    $this->actingAs($f['owner'], 'tenant')->post(
        "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes",
        [
            'title' => 'Wrong lesson',
            'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'lesson_id' => $foreignLesson->id,
        ]
    )->assertSessionHasErrors('lesson_id');
});

test('jitsi_room_name, status, tenant_id and created_by posted by the client are ignored', function () {
    $f = LiveClassFixtures::setup();

    $response = $this->actingAs($f['owner'], 'tenant')->post(
        "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes",
        [
            'title' => 'Hostile payload',
            'starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
            // Every server-owned column, posted alongside the legitimate fields:
            'jitsi_room_name' => 'client-supplied-room',
            'status' => 'ended',
            'created_by' => 999999,
            'tenant_id' => 999999,
        ]
    );

    $response->assertRedirect();

    $class = inTenant($f['tenant'], fn () => LiveClass::firstOrFail());

    expect($class->jitsi_room_name)->not->toBe('client-supplied-room')
        ->and($class->jitsi_room_name)->toMatch('/^[A-Za-z0-9]{32}-[0-9a-f]{8}$/')
        ->and($class->status)->toBe(LiveClassStatus::Scheduled)
        ->and((int) $class->created_by)->toBe($f['owner']->id)
        ->and((int) $class->tenant_id)->toBe($f['tenant']->id);
});

test('room names are unpredictable and not derivable from class, course or tenant ids', function () {
    $f = LiveClassFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')->post(
        "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes",
        ['title' => 'First', 'starts_at' => now()->addHour()->format('Y-m-d H:i:s')]
    )->assertRedirect();

    $this->actingAs($f['owner'], 'tenant')->post(
        "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes",
        ['title' => 'Second', 'starts_at' => now()->addHour()->format('Y-m-d H:i:s')]
    )->assertRedirect();

    [$first, $second] = inTenant($f['tenant'], fn () => LiveClass::orderBy('id')->get());

    expect($first->jitsi_room_name)->not->toBe($second->jitsi_room_name);

    foreach ([$first, $second] as $class) {
        expect($class->jitsi_room_name)
            ->not->toContain((string) $class->id)
            ->not->toContain((string) $f['course']->id)
            ->not->toContain((string) $f['tenant']->id);
    }
});

test('room names stay unpredictable even for classes with identical business fields', function () {
    $f = LiveClassFixtures::setup();

    // Twelve classes, byte-identical title/window/course: if the room name were
    // derived from any of those fields, some of these twelve would collide.
    for ($i = 0; $i < 12; $i++) {
        LiveClassFixtures::liveClass($f['course'], $f['owner'], [
            'title' => 'Identical session',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);
    }

    $rooms = inTenant($f['tenant'], fn () => LiveClass::pluck('jitsi_room_name'));

    expect($rooms)->toHaveCount(12)
        ->and($rooms->unique())->toHaveCount(12);

    foreach ($rooms as $room) {
        expect($room)
            ->toMatch('/^[A-Za-z0-9]{32}-[0-9a-f]{8}$/')
            ->not->toContain((string) $f['course']->id)
            ->not->toContain('Identical session');
    }
});

// === Edit / cancel / delete ===

test('a cancelled class cannot be edited', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner'], ['title' => 'Before cancel']);

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/cancel")
        ->assertRedirect();

    expect(inTenant($f['tenant'], fn () => LiveClass::firstOrFail()->status))->toBe(LiveClassStatus::Cancelled);

    // Any further edit must bounce with a friendly error and leave the row alone
    $this->actingAs($f['owner'], 'tenant')
        ->from("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/edit")
        ->patch("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}", [
            'title' => 'Sneaky edit',
            'starts_at' => $class->starts_at->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/edit")
        ->assertSessionHasErrors(['live_class' => __('live_classes.cannot_edit_cancelled')]);

    expect(inTenant($f['tenant'], fn () => LiveClass::firstOrFail()->title))->toBe('Before cancel');
});

test('an owner can edit a scheduled class', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner']);

    $this->actingAs($f['owner'], 'tenant')
        ->patch("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}", [
            'title' => 'Renamed session',
            'starts_at' => $class->starts_at->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}/edit");

    expect(inTenant($f['tenant'], fn () => LiveClass::firstOrFail()->title))->toBe('Renamed session');
});

test('a student cannot delete a live class; an owner can', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner']);
    $url = "http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/{$class->id}";

    $this->actingAs($f['student'], 'tenant')->delete($url)->assertForbidden();

    $this->actingAs($f['owner'], 'tenant')->delete($url)
        ->assertRedirect("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes");

    expect(inTenant($f['tenant'], fn () => LiveClass::first()))->toBeNull();
});

// === Index ===

test('the live-classes index lists past and upcoming classes for staff and 403s students', function () {
    $f = LiveClassFixtures::setup();
    $past = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Past: mock exam debrief',
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
        'status' => 'ended',
    ]);
    $upcoming = LiveClassFixtures::liveClass($f['course'], $f['owner'], [
        'title' => 'Upcoming: revision sprint',
        'starts_at' => now()->addDay(),
    ]);

    $index = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes");

    $index->assertOk()
        ->assertSee($past->title)
        ->assertSee($upcoming->title);

    // The create form sits behind the same policy
    $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes/create")
        ->assertOk();

    $this->actingAs($f['staff'], 'tenant')
        ->get("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes")
        ->assertOk();

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/manage/courses/{$f['course']->id}/live-classes")
        ->assertForbidden();
});
