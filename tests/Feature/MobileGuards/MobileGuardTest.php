<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;
use Tests\Support\MobileSafetyChecker;

function mobileGuardFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $course, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$owner, $course, $lesson];
    });

    return [$tenant, $domain, $owner, $course, $lesson];
}

// === Pages that already exist today: allowed to pass now, kept here as regression guards ===

test('pages that already exist today (login, dashboard, People, lesson, and — pre-dating Phase 9 — the progress page) are mobile-safe', function () {
    [$tenant, $domain, $owner, $course, $lesson] = mobileGuardFixture();

    $login = $this->get("http://{$domain}/login")->getContent();
    expect(MobileSafetyChecker::violations($login))->toBe([]);

    $this->actingAs($owner, 'tenant');

    $dashboard = $this->get("http://{$domain}/dashboard")->getContent();
    expect(MobileSafetyChecker::violations($dashboard))->toBe([]);

    $people = $this->get("http://{$domain}/users")->getContent();
    expect(MobileSafetyChecker::violations($people))->toBe([]);

    $lessonPage = $this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")->getContent();
    expect(MobileSafetyChecker::violations($lessonPage))->toBe([]);

    // The progress INDEX page (not the new export endpoint) is Phase 7 work, already
    // wrapped in overflow-x-auto — it genuinely already passes today, not because of any
    // Phase 9 change. Included here rather than in the "Phase 9 pages" group below so a
    // currently-true pass isn't mistaken for one this phase is responsible for.
    $progress = $this->get("http://{$domain}/manage/courses/{$course->id}/progress")->getContent();
    expect(MobileSafetyChecker::violations($progress))->toBe([]);
});

// === Phase 9 pages: not implemented yet, expected to fail until Step 2 ===

test('the import preview page is mobile-safe', function () {
    [, $domain, $owner] = mobileGuardFixture();

    $preview = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile("name,phone,email\nAsha Rao,9876543210,\n")]);

    // Positive control: a missing route currently renders the app's own 404 page, which
    // has a viewport meta tag, no table, and no fixed-width class — i.e. it would pass
    // the mobile-safety checker vacuously. Requiring 200 first ties this test to the real
    // preview page actually rendering.
    $preview->assertOk();

    expect(MobileSafetyChecker::violations($preview->getContent()))->toBe([]);
});

test('the credentials sheet is mobile-safe', function () {
    [$tenant, $domain, $owner] = mobileGuardFixture();

    $preview = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile("name,phone,email\nAsha Rao,9876543210,\n")]);
    $token = $preview->viewData('token');
    $confirm = $this->actingAs($owner, 'tenant')->post("http://{$domain}/users/import/confirm", ['token' => $token, 'guardian_consent' => '1']);
    $sheetToken = $confirm->getSession()->get('import_sheet_token');

    $sheet = $this->actingAs($owner, 'tenant')->get("http://{$domain}/users/import/sheet/{$sheetToken}");
    $sheet->assertOk();

    expect(MobileSafetyChecker::violations($sheet->getContent()))->toBe([]);
});

test('the Help page is mobile-safe', function () {
    [, $domain, $owner] = mobileGuardFixture();

    $help = $this->actingAs($owner, 'tenant')->get("http://{$domain}/manage/help");

    // Positive control, same reasoning as the import preview test above: a 404 page
    // would otherwise satisfy the mobile-safety checker vacuously.
    $help->assertOk();

    expect(MobileSafetyChecker::violations($help->getContent()))->toBe([]);
});
