<?php

namespace Tests\Support;

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\Tenant;
use App\Models\User;

/**
 * Shared fixtures for the Phase 12 live-class tests
 * (docs/specs/phase-12-live-classes.md).
 *
 * Lives in tests/Support (PSR-4 as Tests\Support\*) for the same reason as
 * PaymentFixtures: Pest loads every test file in one process, so a helper
 * function declared in two files would be a fatal redeclaration. All helpers
 * are static methods instead.
 *
 * setup() also flips the feature on (coaching.live_classes_enabled plus the
 * JaaS keys), so every test starts from "the feature is deployed and
 * configured"; tests that care about the off state flip it back.
 */
class LiveClassFixtures
{
    /** Host every fixture tenant resolves from unless a test overrides it. */
    public const DOMAIN = 'liveclasses.coaching.test';

    /** Config that means "live classes are deployed and JaaS is configured". */
    public const ENABLED = [
        'coaching.live_classes_enabled' => true,
        'services.jitsi.app_id' => 'test-jitsi-app-id',
        'services.jitsi.app_secret' => 'test-jitsi-secret-abc123',
    ];

    /**
     * A tenant with one published course (+ chapter/lesson), $options['students']
     * actively enrolled students, and one same-tenant student with no enrolment
     * (the 403 control).
     *
     * Options:
     *  - students (int)   enrolled students, default 1
     *  - domain   (string) this tenant's host, default self::DOMAIN (cross-tenant
     *                      tests build a second fixture with a different domain)
     *
     * @return array{tenant: Tenant, domain: string, owner: User, staff: User, course: Course, chapter: Chapter, lesson: Lesson, students: list<User>, student: User, outsider: User}
     */
    public static function setup(array $options = []): array
    {
        $studentCount = max(1, (int) ($options['students'] ?? 1));
        $domain = $options['domain'] ?? self::DOMAIN;

        config(self::ENABLED);

        $tenant = Tenant::factory()->create();
        $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

        [$owner, $staff, $course, $chapter, $lesson, $students, $outsider] = inTenant($tenant, function () use ($studentCount) {
            $owner = User::factory()->owner()->create();
            $staff = User::factory()->staff()->create();
            $course = Course::factory()->published()->create();
            $chapter = Chapter::factory()->for($course)->create();
            $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);

            $students = [];

            for ($i = 0; $i < $studentCount; $i++) {
                $student = User::factory()->student()->create();
                Enrolment::factory()->for($course)->for($student, 'user')->active()->create();
                $students[] = $student;
            }

            $outsider = User::factory()->student()->create();

            return [$owner, $staff, $course, $chapter, $lesson, $students, $outsider];
        });

        return [
            'tenant' => $tenant,
            'domain' => $domain,
            'owner' => $owner,
            'staff' => $staff,
            'course' => $course,
            'chapter' => $chapter,
            'lesson' => $lesson,
            'students' => $students,
            'student' => $students[0],
            'outsider' => $outsider,
        ];
    }

    /**
     * A live class in $course. Defaults to "scheduled, starting in an hour,
     * ending in two". $attributes overrides any of: title, description,
     * starts_at, ends_at (pass null explicitly for no end), lesson_id,
     * status.
     *
     * The room name is intentionally NOT set here: the model generates it on
     * creating, exactly as it will in production.
     */
    public static function liveClass(Course $course, User $creator, array $attributes = []): LiveClass
    {
        return inTenant(Tenant::findOrFail($course->tenant_id), function () use ($course, $creator, $attributes) {
            $class = new LiveClass;
            $class->fill([
                'course_id' => $course->id,
                'title' => $attributes['title'] ?? 'Algebra Live Session',
                'description' => $attributes['description'] ?? 'Fixture live class',
                'starts_at' => $attributes['starts_at'] ?? now()->addHour(),
                'ends_at' => array_key_exists('ends_at', $attributes) ? $attributes['ends_at'] : now()->addHours(2),
                'lesson_id' => $attributes['lesson_id'] ?? null,
            ]);
            $class->forceFill([
                'status' => $attributes['status'] ?? 'scheduled',
                'created_by' => $creator->id,
            ]);
            $class->save();

            return $class->fresh();
        });
    }

    /**
     * An attendance row for $user in $class, written the way the heartbeat
     * writer will write it: every guarded column through forceFill.
     *
     * Defaults: a 30-minute session ending now with duration_seconds = 1800.
     */
    public static function attend(LiveClass $class, User $user, array $attributes = []): LiveClassAttendance
    {
        return inTenant(Tenant::findOrFail($class->tenant_id), function () use ($class, $user, $attributes) {
            $attendance = new LiveClassAttendance;
            $attendance->forceFill([
                'live_class_id' => $class->id,
                'user_id' => $user->id,
                'joined_at' => $attributes['joined_at'] ?? now()->subMinutes(30),
                'last_seen_at' => $attributes['last_seen_at'] ?? now()->subMinutes(30),
                'left_at' => $attributes['left_at'] ?? now(),
                'duration_seconds' => $attributes['duration_seconds'] ?? 1800,
            ])->save();

            return $attendance;
        });
    }

    /**
     * Decodes the payload out of the JWT carried in a join redirect's Location
     * header. Returns null when the header holds no parseable JWT — tests then
     * fail on the null access, which is exactly the right failure mode.
     */
    public static function joinJwt(string $location): ?array
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        if (! isset($query['jwt'])) {
            return null;
        }

        $segments = explode('.', $query['jwt']);

        if (count($segments) !== 3) {
            return null;
        }

        return json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true);
    }
}
