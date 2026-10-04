<?php

namespace Tests\Support;

use App\Models\Chapter;
use App\Models\Consent;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LiveClassAttendance;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the Phase 15 DPDP consent-foundation tests.
 *
 * Lives in tests/Support (PSR-4 as Tests\Support\*) rather than in a test file so
 * that every file under tests/Feature/Consents can build the same shape without
 * declaring a global helper twice — Pest loads each test file in the same process,
 * so two files declaring the same function name would be a fatal redeclaration
 * (the same reasoning documented on PaymentFixtures).
 *
 * The `use App\Models\Consent` import resolves only when a method that needs a
 * Consent is actually called: before Step 2 that class does not exist, which is
 * exactly the failure the Step 1 tests are written to produce.
 */
class ConsentFixtures
{
    /** The notice version every consent row must be stamped with (per the brief). */
    public const NOTICE_VERSION = 'v1.0-2026-10-04';

    /** The fixed name an erased student's row is renamed to (per the brief). */
    public const ERASED_NAME = 'Deleted Student';

    /** How bulk-import consents are recorded: one file-level method for the file. */
    public const IMPORT_METHOD = 'guardian_in_person';

    /** Every consent purpose, in the order the creation form renders them. */
    public const PURPOSES = [
        'course_delivery',
        'progress_tracking',
        'communication',
        'media_processing',
    ];

    /**
     * A tenant with a real subdomain (resolution is host-based, so a test that
     * never gives the tenant a domain can't reach any tenant route at all), an
     * owner, a staff member, a student, a published course and that student's
     * active enrolment — the base shape almost every consent test starts from.
     *
     * @return array{tenant: Tenant, domain: string, owner: User, staff: User, student: User, course: Course, enrolment: Enrolment}
     */
    public static function setup(string $domain = 'tenant-a.coaching.test'): array
    {
        $tenant = Tenant::factory()->create();
        $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

        [$owner, $staff, $student, $course, $enrolment] = inTenant($tenant, function () {
            $owner = User::factory()->owner()->create();
            $staff = User::factory()->staff()->create();
            $student = User::factory()->student()->create();
            $course = Course::factory()->published()->create();
            $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

            return [$owner, $staff, $student, $course, $enrolment];
        });

        return compact('tenant', 'domain', 'owner', 'staff', 'student', 'course', 'enrolment');
    }

    /**
     * The guardian block a manual student-creation request must carry.
     *
     * @return array<string, string>
     */
    public static function guardianFields(): array
    {
        return [
            'guardian_name' => 'Meera Rao',
            'guardian_relationship' => 'Mother',
            'guardian_phone' => '9876500001',
            'guardian_email' => 'meera@example.com',
        ];
    }

    /**
     * A complete, valid POST /users payload for a new student: contact details,
     * guardian details and a recorded consent for every purpose.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function studentCreationPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Aarav Student',
            'email' => 'dpdp-student@example.com',
            'phone' => '9876500000',
            'role' => 'student',
        ] + self::guardianFields() + [
            'consents' => self::PURPOSES,
            'consent_method' => self::IMPORT_METHOD,
        ];
    }

    /**
     * The payload for POST /manage/students/{user}/consents — one purpose per
     * request, plus the method the consent was collected with.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function recordPayload(string $purpose, array $overrides = []): array
    {
        return $overrides + [
            'purpose' => $purpose,
            'consent_method' => self::IMPORT_METHOD,
        ];
    }

    /**
     * The consents screen for one student: list, record and (per consent) the
     * withdraw route hanging off it.
     */
    public static function consentsUrl(string $domain, User $student): string
    {
        return "http://{$domain}/manage/students/{$student->id}/consents";
    }

    /** The withdraw form/endpoint for one consent row. */
    public static function withdrawUrl(string $domain, User $student, int $consentId): string
    {
        return "http://{$domain}/manage/students/{$student->id}/consents/{$consentId}/withdraw";
    }

    /** The student-data screen (export link + erasure confirmation form). */
    public static function dataUrl(string $domain, User $student): string
    {
        return "http://{$domain}/manage/students/{$student->id}/data";
    }

    /** The JSON download of one student's data. */
    public static function exportUrl(string $domain, User $student): string
    {
        return "http://{$domain}/manage/students/{$student->id}/data/export";
    }

    /**
     * Guardian details written the way a controller writes guarded columns
     * (forceFill), for tests whose student came from a factory rather than from
     * the consent-aware creation flow.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function forceGuardian(Tenant $tenant, User $student, array $overrides = []): User
    {
        inTenant($tenant, function () use ($student, $overrides) {
            $student->forceFill($overrides + self::guardianFields())->save();
        });

        return $student;
    }

    /**
     * A consent row written straight to the schema (guarded columns via
     * forceFill), for tests whose subject is withdrawal, export or erasure
     * rather than the act of recording.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function consentRow(Tenant $tenant, User $student, User $actor, string $purpose, array $overrides = []): Consent
    {
        return inTenant($tenant, function () use ($tenant, $student, $actor, $purpose, $overrides) {
            $consent = new Consent;
            $consent->forceFill($overrides + [
                'tenant_id' => $tenant->id,
                'user_id' => $student->id,
                'purpose' => $purpose,
                'notice_version' => self::NOTICE_VERSION,
                'method' => self::IMPORT_METHOD,
                'granted_at' => now(),
                'recorded_by' => $actor->id,
            ]);
            $consent->save();

            return $consent;
        });
    }

    /**
     * A published chapter/lesson inside $course, for the lesson-page access
     * assertions. The youtube id mirrors AccessRulesTest so the lesson page
     * has a video to render.
     */
    public static function lesson(Tenant $tenant, Course $course): Lesson
    {
        return inTenant($tenant, function () use ($course) {
            $chapter = Chapter::factory()->for($course)->create();

            return Lesson::factory()->for($chapter)->published()->create([
                'course_id' => $course->id,
                'youtube_video_id' => 'dQw4w9WgXcQ',
            ]);
        });
    }

    /**
     * One watched-lesson row for $student.
     */
    public static function progress(Tenant $tenant, Course $course, Lesson $lesson, User $student): LessonProgress
    {
        return inTenant($tenant, function () use ($course, $lesson, $student) {
            return LessonProgress::factory()
                ->for($lesson)
                ->for($course)
                ->for($student, 'user')
                ->create();
        });
    }

    /**
     * One registered device row for $student (the columns DeviceRegistrar
     * normally writes; first/last seen default via useCurrent()).
     */
    public static function device(Tenant $tenant, User $student): UserDevice
    {
        return inTenant($tenant, function () use ($student) {
            $device = new UserDevice;
            $device->forceFill([
                'user_id' => $student->id,
                'device_id' => 'device-'.Str::random(16),
                'label' => 'Test phone',
            ])->save();

            return $device;
        });
    }

    /**
     * One live-class attendance stay for $student in a class in $course.
     * LiveClassFixtures resolves the tenant from the class row itself.
     */
    public static function attendance(Course $course, User $creator, User $student): LiveClassAttendance
    {
        $class = LiveClassFixtures::liveClass($course, $creator);

        return LiveClassFixtures::attend($class, $student);
    }

    /**
     * setup() plus every child row the erasure/export contracts touch: a
     * published lesson, a progress row, a device row, a live-class attendance
     * row and a payment against the student's enrolment.
     *
     * @return array{tenant: Tenant, domain: string, owner: User, staff: User, student: User, course: Course, enrolment: Enrolment, lesson: Lesson, progress: LessonProgress, device: UserDevice, attendance: LiveClassAttendance, payment: Payment}
     */
    public static function erasureSetup(string $domain = 'tenant-a.coaching.test'): array
    {
        $f = self::setup($domain);

        $f['lesson'] = self::lesson($f['tenant'], $f['course']);
        $f['progress'] = self::progress($f['tenant'], $f['course'], $f['lesson'], $f['student']);
        $f['device'] = self::device($f['tenant'], $f['student']);
        $f['attendance'] = self::attendance($f['course'], $f['owner'], $f['student']);
        $f['payment'] = PaymentFixtures::payment($f['tenant'], $f['enrolment']);

        return $f;
    }
}
