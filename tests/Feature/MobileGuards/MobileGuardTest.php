<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;

/**
 * Shared 360px-safety assertions for every key tenant page: any <table> sits inside an
 * overflow-x-auto wrapper or is hidden below sm; no class carries a fixed width above
 * 340px; the viewport meta tag is present.
 */
function assertMobileSafe(string $html, string $page): void
{
    expect($html)->toContain('name="viewport"');

    if (str_contains($html, '<table')) {
        $hasWrapper = preg_match('/overflow-x-auto[^>]*>\s*<table/s', $html) === 1;
        $isHiddenBelowSm = preg_match('/class="[^"]*\bhidden\b[^"]*\bsm:(table|block)\b[^"]*"[^>]*>\s*<table/s', $html) === 1
            || preg_match('/<table[^>]*class="[^"]*\bhidden\b[^"]*\bsm:table\b/s', $html) === 1;

        expect($hasWrapper || $isHiddenBelowSm)
            ->toBeTrue("Page [{$page}] has a <table> that is neither overflow-x-auto wrapped nor hidden below sm.");
    }

    preg_match_all('/class="([^"]*)"/', $html, $matches);
    foreach ($matches[1] as $classAttr) {
        foreach (explode(' ', $classAttr) as $class) {
            if (preg_match('/^w-\[(\d+)px\]$/', $class, $m)) {
                expect((int) $m[1])->toBeLessThanOrEqual(340, "Page [{$page}] has a fixed width above 340px ({$class}).");
            }
        }
    }
}

test('key tenant pages are safe at 360px width', function () {
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

    assertMobileSafe($this->get("http://{$domain}/login")->getContent(), 'login');

    $this->actingAs($owner, 'tenant');
    assertMobileSafe($this->get("http://{$domain}/dashboard")->getContent(), 'dashboard');
    assertMobileSafe($this->get("http://{$domain}/users")->getContent(), 'people');
    assertMobileSafe($this->get("http://{$domain}/manage/help")->getContent(), 'help');
    assertMobileSafe($this->get("http://{$domain}/manage/courses/{$course->id}/progress")->getContent(), 'progress');
    assertMobileSafe($this->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}")->getContent(), 'lesson');

    $preview = $this->post("http://{$domain}/users/import", ['file' => csvUploadFile("name,phone,email\nAsha Rao,9876543210,\n")]);
    assertMobileSafe($preview->getContent(), 'import-preview');

    $token = $preview->viewData('token');
    $confirm = $this->post("http://{$domain}/users/import/confirm", ['token' => $token]);
    $sheetToken = $confirm->getSession()->get('import_sheet_token');
    assertMobileSafe($this->get("http://{$domain}/users/import/sheet/{$sheetToken}")->getContent(), 'credentials-sheet');
});
