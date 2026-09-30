<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

function heartbeatFixture(bool $publish = true): array
{
    $tenant = Tenant::factory()->create();
    $domain = Str::random(8).'.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $lesson = inTenant($tenant, function () use ($publish) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lessonFactory = Lesson::factory()->for($chapter);

        return ($publish ? $lessonFactory->published() : $lessonFactory)->create(['course_id' => $course->id]);
    });

    return [$tenant, $domain, $lesson];
}

function enrolledStudent(Tenant $tenant, Course $course): User
{
    return inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });
}

function heartbeatPayload(array $overrides = []): array
{
    return array_merge([
        'position' => 10,
        'duration' => 100,
        'played' => 10,
    ], $overrides);
}

test('an enrolled active student can post a heartbeat', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = enrolledStudent($tenant, $course);

    $response = $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload());

    $response->assertOk()->assertJsonStructure(['watched_seconds', 'completed']);
});

test('a non-enrolled student is forbidden', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertForbidden();
});

test('an expired enrolment is forbidden', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        return $student;
    });

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertForbidden();
});

test('a revoked enrolment is forbidden', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->revoked()->create();

        return $student;
    });

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertForbidden();
});

test('a guest gets a 401 for a JSON heartbeat request', function () {
    [, $domain, $lesson] = heartbeatFixture();

    $this->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertUnauthorized();
});

test('a disabled student is logged out and cannot post a heartbeat', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = enrolledStudent($tenant, $course);

    $this->actingAs($student, 'tenant');
    inTenant($tenant, fn () => $student->forceFill(['status' => 'disabled'])->save());

    $this->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertUnauthorized();
});

test('a must-change-password student is redirected instead of recording progress', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->mustChangePassword()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });

    $this->actingAs($student, 'tenant')
        ->post("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertRedirect();
});

test('owner gets a 204 and nothing is stored', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertNoContent();

    inTenant($tenant, fn () => expect(LessonProgress::count())->toBe(0));
});

test('staff gets a 204 and nothing is stored', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $this->actingAs($staff, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertNoContent();

    inTenant($tenant, fn () => expect(LessonProgress::count())->toBe(0));
});

test('another tenant\'s lesson 404s', function () {
    [$tenantA, $domainA, $lessonA] = heartbeatFixture();
    [$tenantB, , $lessonB] = heartbeatFixture();
    $courseA = inTenant($tenantA, fn () => $lessonA->course);
    $courseB = inTenant($tenantB, fn () => $lessonB->course);
    enrolledStudent($tenantB, $courseB);
    $student = enrolledStudent($tenantA, $courseA);

    // Positive control: this same student, on their own tenant's domain, can post a
    // heartbeat for their own tenant's lesson.
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domainA}/lessons/{$lessonA->id}/progress", heartbeatPayload())
        ->assertOk();

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domainA}/lessons/{$lessonB->id}/progress", heartbeatPayload())
        ->assertNotFound();
});

test('a draft lesson 404s', function () {
    [$tenant, $domain, $draftLesson] = heartbeatFixture(publish: false);
    $publishedLesson = inTenant($tenant, function () use ($draftLesson) {
        $chapter = $draftLesson->chapter;

        return Lesson::factory()->for($chapter)->published()->create(['course_id' => $draftLesson->course_id]);
    });
    $course = inTenant($tenant, fn () => $draftLesson->course);
    $student = enrolledStudent($tenant, $course);

    // Positive control: a published lesson in the same course accepts a heartbeat.
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$publishedLesson->id}/progress", heartbeatPayload())
        ->assertOk();

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$draftLesson->id}/progress", heartbeatPayload())
        ->assertNotFound();
});

test('the central domain never resolves the progress route', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = enrolledStudent($tenant, $course);
    $central = config('tenancy.central_domains')[0] ?? 'coaching.test';

    // Positive control: the tenant's own domain accepts the same request.
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertOk();

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$central}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertNotFound();
});

// === Validation ===

test('a negative position is rejected', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = enrolledStudent($tenant, $course);

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload(['position' => -1]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('position');
});

test('a huge duration beyond 86400 seconds is rejected', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = enrolledStudent($tenant, $course);

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload(['duration' => 90000]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('duration');
});

test('a played value beyond 120 seconds is rejected', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = enrolledStudent($tenant, $course);

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload(['played' => 121]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('played');
});

test('a non-numeric position is rejected', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = enrolledStudent($tenant, $course);

    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload(['position' => 'nope']))
        ->assertUnprocessable();
});

// === Throttle ===

test('the heartbeat endpoint is throttled to 12 requests per minute per user per lesson', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = enrolledStudent($tenant, $course);

    // Positive control: the first request is not throttled.
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertOk();

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($student, 'tenant')
            ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload());
    }

    // 12th request still within limit.
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertOk();

    // 13th request within the same minute is throttled.
    $this->actingAs($student, 'tenant')
        ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload())
        ->assertStatus(429);
});

test('a throttled HTML request gets the friendly 429 page, not raw JSON', function () {
    [$tenant, $domain, $lesson] = heartbeatFixture();
    $course = inTenant($tenant, fn () => $lesson->course);
    $student = enrolledStudent($tenant, $course);

    for ($i = 0; $i < 12; $i++) {
        $this->actingAs($student, 'tenant')
            ->postJson("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload());
    }

    $response = $this->actingAs($student, 'tenant')
        ->post("http://{$domain}/lessons/{$lesson->id}/progress", heartbeatPayload());

    $response->assertStatus(429);
    expect($response->headers->get('content-type'))->toContain('text/html');
});
