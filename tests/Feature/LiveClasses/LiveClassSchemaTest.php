<?php

use App\Enums\LiveClassStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LiveClassFixtures;

// === Columns and indexes ===

test('the live_classes table carries every column the feature writes', function () {
    expect(Schema::hasTable('live_classes'))->toBeTrue();

    expect(collect([
        'id',
        'tenant_id',
        'course_id',
        'lesson_id',
        'created_by',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'status',
        'jitsi_room_name',
        'created_at',
        'updated_at',
    ])->every(fn (string $column) => Schema::hasColumn('live_classes', $column)))->toBeTrue();
});

test('the live_class_attendance table carries every column the recorder writes', function () {
    expect(Schema::hasTable('live_class_attendance'))->toBeTrue();

    expect(collect([
        'id',
        'tenant_id',
        'live_class_id',
        'user_id',
        'joined_at',
        'last_seen_at',
        'left_at',
        'duration_seconds',
        'created_at',
        'updated_at',
    ])->every(fn (string $column) => Schema::hasColumn('live_class_attendance', $column)))->toBeTrue();
});

test('live_classes is unique per (tenant, room name) and indexed for the schedule and dashboard queries', function () {
    $indexes = Schema::getIndexes('live_classes');

    expect(collect($indexes)->contains(
        fn (array $index) => $index['unique'] === true && $index['columns'] === ['tenant_id', 'id']
    ))->toBeTrue();

    expect(collect($indexes)->contains(
        fn (array $index) => $index['unique'] === true && $index['columns'] === ['tenant_id', 'jitsi_room_name']
    ))->toBeTrue();

    expect(collect($indexes)->contains(
        fn (array $index) => $index['unique'] === false && $index['columns'] === ['tenant_id', 'course_id', 'starts_at']
    ))->toBeTrue();

    expect(collect($indexes)->contains(
        fn (array $index) => $index['unique'] === false && $index['columns'] === ['tenant_id', 'status', 'starts_at']
    ))->toBeTrue();
});

test('live_class_attendance is unique per (tenant, class, user, joined_at) and indexed for the report query', function () {
    $indexes = Schema::getIndexes('live_class_attendance');

    expect(collect($indexes)->contains(
        fn (array $index) => $index['unique'] === true && $index['columns'] === ['tenant_id', 'id']
    ))->toBeTrue();

    expect(collect($indexes)->contains(
        fn (array $index) => $index['unique'] === true && $index['columns'] === ['tenant_id', 'live_class_id', 'user_id', 'joined_at']
    ))->toBeTrue();

    expect(collect($indexes)->contains(
        fn (array $index) => $index['unique'] === false && $index['columns'] === ['tenant_id', 'live_class_id', 'user_id']
    ))->toBeTrue();
});

// === Composite FK: live_classes -> courses ===

test('a live class cannot reference a course from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $courseA = inTenant($tenantA, fn () => Course::factory()->published()->create());
    $courseB = inTenant($tenantB, fn () => Course::factory()->published()->create());

    // Positive control: a live class referencing its own tenant's course succeeds
    inTenant($tenantA, function () use ($courseA, $tenantA) {
        $owner = User::factory()->owner()->create();
        $class = new LiveClass;
        $class->fill(['course_id' => $courseA->id, 'title' => 'Own tenant class', 'starts_at' => now()->addHour()]);
        $class->forceFill(['created_by' => $owner->id, 'status' => 'scheduled']);
        $class->save();

        expect((int) $class->tenant_id)->toBe($tenantA->id);
    });

    // Negative: course_id points at tenant B's course while operating as tenant A
    expect(fn () => inTenant($tenantA, function () use ($courseB) {
        $owner = User::factory()->owner()->create();
        $class = new LiveClass;
        $class->fill(['course_id' => $courseB->id, 'title' => 'Cross-tenant class', 'starts_at' => now()->addHour()]);
        $class->forceFill(['created_by' => $owner->id, 'status' => 'scheduled']);
        $class->save();
    }))->toThrow(QueryException::class);
});

// === Composite FK: live_classes -> lessons ===

test('a live class cannot reference a lesson from another tenant', function () {
    $tenantA = Tenant::factory()->create();

    $lessonA = inTenant($tenantA, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
    });

    $fB = LiveClassFixtures::setup(['domain' => 'liveclasses-b.coaching.test']);
    $lessonB = $fB['lesson'];

    // The two lessons must genuinely belong to different tenants, or the
    // negative case below proves nothing.
    expect((int) $fB['tenant']->id)->not->toBe($tenantA->id);

    // Positive control: same-tenant lesson reference succeeds
    inTenant($tenantA, function () use ($lessonA, $tenantA) {
        $owner = User::factory()->owner()->create();
        $class = new LiveClass;
        $class->fill([
            'course_id' => $lessonA->course_id,
            'lesson_id' => $lessonA->id,
            'title' => 'Own tenant lesson link',
            'starts_at' => now()->addHour(),
        ]);
        $class->forceFill(['created_by' => $owner->id, 'status' => 'scheduled']);
        $class->save();

        expect((int) $class->tenant_id)->toBe($tenantA->id);
    });

    // Negative: lesson_id points at tenant B's lesson while operating as tenant A
    expect(fn () => inTenant($tenantA, function () use ($lessonB) {
        $owner = User::factory()->owner()->create();
        $class = new LiveClass;
        $class->fill([
            'course_id' => $lessonB->course_id,
            'lesson_id' => $lessonB->id,
            'title' => 'Cross-tenant lesson link',
            'starts_at' => now()->addHour(),
        ]);
        $class->forceFill(['created_by' => $owner->id, 'status' => 'scheduled']);
        $class->save();
    }))->toThrow(QueryException::class);
});

// === Composite FK: live_classes -> users (created_by) ===

test('a live class cannot name a creator from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ownerA = inTenant($tenantA, fn () => User::factory()->owner()->create());
    $ownerB = inTenant($tenantB, fn () => User::factory()->owner()->create());

    // Positive control: own-tenant creator succeeds
    inTenant($tenantA, function () use ($ownerA, $tenantA) {
        $course = Course::factory()->published()->create();
        $class = new LiveClass;
        $class->fill(['course_id' => $course->id, 'title' => 'Own creator', 'starts_at' => now()->addHour()]);
        $class->forceFill(['created_by' => $ownerA->id, 'status' => 'scheduled']);
        $class->save();

        expect((int) $class->tenant_id)->toBe($tenantA->id);
    });

    // Negative: created_by points at tenant B's user while operating as tenant A
    expect(fn () => inTenant($tenantA, function () use ($ownerB) {
        $course = Course::factory()->published()->create();
        $class = new LiveClass;
        $class->fill(['course_id' => $course->id, 'title' => 'Foreign creator', 'starts_at' => now()->addHour()]);
        $class->forceFill(['created_by' => $ownerB->id, 'status' => 'scheduled']);
        $class->save();
    }))->toThrow(QueryException::class);
});

// === Composite FK: live_class_attendance -> live_classes ===

test('an attendance row cannot reference a live class from another tenant', function () {
    $fA = LiveClassFixtures::setup();
    $classA = LiveClassFixtures::liveClass($fA['course'], $fA['owner']);

    $tenantB = Tenant::factory()->create();
    $classB = inTenant($tenantB, function () {
        $course = Course::factory()->published()->create();
        $owner = User::factory()->owner()->create();
        $class = new LiveClass;
        $class->fill(['course_id' => $course->id, 'title' => 'Tenant B class', 'starts_at' => now()->addHour()]);
        $class->forceFill(['created_by' => $owner->id, 'status' => 'scheduled']);
        $class->save();

        return $class;
    });

    // Positive control: attendance inside its own tenant succeeds
    inTenant($fA['tenant'], function () use ($classA, $fA) {
        $attendance = new LiveClassAttendance;
        $attendance->forceFill([
            'live_class_id' => $classA->id,
            'user_id' => $fA['student']->id,
            'joined_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        expect((int) $attendance->tenant_id)->toBe($fA['tenant']->id);
    });

    // Negative: live_class_id points at tenant B's class while operating as tenant A
    expect(fn () => inTenant($fA['tenant'], function () use ($classB, $fA) {
        $attendance = new LiveClassAttendance;
        $attendance->forceFill([
            'live_class_id' => $classB->id,
            'user_id' => $fA['student']->id,
            'joined_at' => now(),
            'last_seen_at' => now(),
        ])->save();
    }))->toThrow(QueryException::class);

    expect((int) $classB->tenant_id)->not->toBe((int) $fA['tenant']->id);
});

// === Composite FK: live_class_attendance -> users ===

test('an attendance row cannot name a participant from another tenant', function () {
    $fA = LiveClassFixtures::setup();
    $classA = LiveClassFixtures::liveClass($fA['course'], $fA['owner']);

    $tenantB = Tenant::factory()->create();
    $foreignStudent = inTenant($tenantB, fn () => User::factory()->student()->create());

    // Positive control: own-tenant participant succeeds
    inTenant($fA['tenant'], function () use ($classA, $fA) {
        $attendance = new LiveClassAttendance;
        $attendance->forceFill([
            'live_class_id' => $classA->id,
            'user_id' => $fA['student']->id,
            'joined_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        expect((int) $attendance->user_id)->toBe($fA['student']->id);
    });

    // Negative: user_id points at tenant B's student while operating as tenant A
    expect(fn () => inTenant($fA['tenant'], function () use ($classA, $foreignStudent) {
        $attendance = new LiveClassAttendance;
        $attendance->forceFill([
            'live_class_id' => $classA->id,
            'user_id' => $foreignStudent->id,
            'joined_at' => now(),
            'last_seen_at' => now(),
        ])->save();
    }))->toThrow(QueryException::class);
});

// === Unique constraints ===

test('two live classes in one tenant can never share a jitsi_room_name', function () {
    $f = LiveClassFixtures::setup();

    // Positive control: two fixture classes get two different generated rooms
    $one = LiveClassFixtures::liveClass($f['course'], $f['owner'], ['title' => 'Class one']);
    $two = LiveClassFixtures::liveClass($f['course'], $f['owner'], ['title' => 'Class two']);

    expect($one->jitsi_room_name)->toMatch('/^[A-Za-z0-9]{32}-[0-9a-f]{8}$/')
        ->and($two->jitsi_room_name)->toMatch('/^[A-Za-z0-9]{32}-[0-9a-f]{8}$/')
        ->and($one->jitsi_room_name)->not->toBe($two->jitsi_room_name);

    // Negative: forcing the same room name in the same tenant violates the unique key
    expect(fn () => inTenant($f['tenant'], function () use ($f, $one) {
        $duplicate = new LiveClass;
        $duplicate->fill(['course_id' => $f['course']->id, 'title' => 'Duplicate room', 'starts_at' => now()->addHour()]);
        $duplicate->forceFill([
            'created_by' => $f['owner']->id,
            'status' => 'scheduled',
            'jitsi_room_name' => $one->jitsi_room_name,
        ]);
        $duplicate->save();
    }))->toThrow(QueryException::class);
});

test('the same user cannot have two attendance rows with the same joined_at in one class', function () {
    $f = LiveClassFixtures::setup(['students' => 2]);
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner']);
    $instant = now()->subMinutes(5);

    // Positive control: two different users, same instant, both save
    LiveClassFixtures::attend($class, $f['students'][0], ['joined_at' => $instant, 'last_seen_at' => $instant]);
    LiveClassFixtures::attend($class, $f['students'][1], ['joined_at' => $instant, 'last_seen_at' => $instant]);

    // Negative: the same user twice at the same instant violates the unique key.
    // The instant is pinned explicitly so a second boundary crossing between two
    // now() calls can never turn this into a passing test by accident.
    expect(fn () => LiveClassFixtures::attend(
        $class,
        $f['students'][0],
        ['joined_at' => $instant, 'last_seen_at' => $instant]
    ))->toThrow(QueryException::class);
});

// === created_by nullability (clarification 1) ===

test('live_classes.created_by is nullable — a class row is never hostage to its creator record', function () {
    $f = LiveClassFixtures::setup();

    // Positive control: the approved path records the real creator…
    $withCreator = LiveClassFixtures::liveClass($f['course'], $f['owner'], ['title' => 'Has creator']);
    expect((int) $withCreator->created_by)->toBe($f['owner']->id);

    // …and the column itself accepts null, so a purged/unresolvable creator can never
    // make a live class row uninsertable (mirrors courses.created_by, which is nullable).
    $withoutCreator = inTenant($f['tenant'], function () use ($f) {
        $class = new LiveClass;
        $class->fill([
            'course_id' => $f['course']->id,
            'title' => 'Creator-less class',
            'starts_at' => now()->addHour(),
        ]);
        $class->forceFill(['status' => 'scheduled', 'created_by' => null]);
        $class->save();

        return $class->fresh();
    });

    expect($withoutCreator->created_by)->toBeNull();
});

// === Mass assignment guards ===

test('tenant_id, status, jitsi_room_name and created_by are not mass-assignable on live_classes', function () {
    $f = LiveClassFixtures::setup();
    $otherTenant = Tenant::factory()->create();

    // Positive control: the approved path (fill + forceFill) can set these
    $viaForce = LiveClassFixtures::liveClass($f['course'], $f['owner'], ['status' => 'live']);
    expect($viaForce->status)->toBe(LiveClassStatus::Live);

    // Negative: raw mass-assignment must not set any guarded field
    $viaMassAssignment = inTenant($f['tenant'], function () use ($f, $otherTenant) {
        return LiveClass::create([
            'course_id' => $f['course']->id,
            'title' => 'Mass Assignment Test',
            'starts_at' => now()->addHour(),
            'status' => 'live',
            'jitsi_room_name' => 'client-supplied-room',
            'created_by' => 999999,
            'tenant_id' => $otherTenant->id,
        ])->fresh();
    });

    expect($viaMassAssignment->status)->toBe(LiveClassStatus::Scheduled)
        ->and($viaMassAssignment->jitsi_room_name)->not->toBe('client-supplied-room')
        ->and($viaMassAssignment->jitsi_room_name)->toMatch('/^[A-Za-z0-9]{32}-[0-9a-f]{8}$/')
        ->and($viaMassAssignment->created_by)->toBeNull()
        ->and((int) $viaMassAssignment->tenant_id)->toBe($f['tenant']->id);
});

test('joined_at, last_seen_at, left_at, duration_seconds and tenant_id are not mass-assignable on attendance', function () {
    $f = LiveClassFixtures::setup();
    $class = LiveClassFixtures::liveClass($f['course'], $f['owner']);

    $row = LiveClassFixtures::attend($class, $f['student'], [
        'joined_at' => now()->subMinutes(10),
        'last_seen_at' => now()->subMinutes(8),
        'duration_seconds' => 120,
    ]);

    // Positive control: the fixture (forceFill path) set every guarded column
    expect((int) $row->duration_seconds)->toBe(120)
        ->and($row->left_at)->toBeNull();

    // Negative: a plain update() routes through fill(), which must ignore all of them
    $before = [
        'joined_at' => $row->joined_at->format('Y-m-d H:i:s'),
        'last_seen_at' => $row->last_seen_at->format('Y-m-d H:i:s'),
        'tenant_id' => (int) $row->tenant_id,
    ];

    $row->update([
        'joined_at' => now()->subYear(),
        'last_seen_at' => now()->addYear(),
        'left_at' => now(),
        'duration_seconds' => 999999,
        'tenant_id' => 999999,
    ]);

    $row->refresh();

    expect((int) $row->duration_seconds)->toBe(120)
        ->and($row->left_at)->toBeNull()
        ->and($row->joined_at->format('Y-m-d H:i:s'))->toBe($before['joined_at'])
        ->and($row->last_seen_at->format('Y-m-d H:i:s'))->toBe($before['last_seen_at'])
        ->and((int) $row->tenant_id)->toBe($before['tenant_id']);
});
