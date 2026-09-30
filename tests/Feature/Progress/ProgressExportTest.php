<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function exportFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());
    $course = inTenant($tenant, fn () => Course::factory()->published()->create());

    return [$tenant, $domain, $owner, $course];
}

test('the export contains the expected columns and rows for enrolled students', function () {
    [$tenant, $domain, $owner, $course] = exportFixture();
    [$student, $lesson] = inTenant($tenant, function () use ($course) {
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);
        $student = User::factory()->student()->create(['name' => 'Asha Rao', 'phone' => '9876543210']);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return [$student, $lesson];
    });

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/manage/courses/{$course->id}/progress/export");

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($response->getContent())->toContain('name,phone,email,enrolled_since,lessons_completed,lessons_total,percent,last_active,enrolment_status');
    expect($response->getContent())->toContain('Asha Rao');
    expect($response->getContent())->toContain('9876543210');
});

test('the filename includes the course slug and today\'s date', function () {
    [$tenant, $domain, $owner, $course] = exportFixture();

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/manage/courses/{$course->id}/progress/export");

    $expected = "progress-{$course->slug}-".now()->format('Y-m-d').'.csv';
    $response->assertHeader('content-disposition', "attachment; filename=\"{$expected}\"");
});

test('the filter and sort query parameters are applied and the export is not paginated', function () {
    [$tenant, $domain, $owner, $course] = exportFixture();
    inTenant($tenant, function () use ($course) {
        for ($i = 0; $i < 30; $i++) {
            $student = User::factory()->student()->create(['name' => "Student {$i}"]);
            Enrolment::factory()->for($course)->for($student, 'user')->active()->create();
        }
    });

    $response = $this->actingAs($owner, 'tenant')
        ->get("http://{$domain}/manage/courses/{$course->id}/progress/export?filter=not_started&sort=last_active");

    $response->assertOk();
    $lines = array_filter(explode("\n", $response->getContent()));
    // header + 30 data rows, never truncated to a page size like 25.
    expect(count($lines))->toBe(31);
});

test('formula injection in a student name is neutralised with a leading quote', function () {
    [$tenant, $domain, $owner, $course] = exportFixture();
    inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create(['name' => '=HYPERLINK("http://evil.example")']);
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();
    });

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/manage/courses/{$course->id}/progress/export");

    expect($response->getContent())->toContain('\'=HYPERLINK');
});

test('a staff member can export progress (positive control)', function () {
    [$tenant, $domain, , $course] = exportFixture();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $this->actingAs($staff, 'tenant')->get("http://{$domain}/manage/courses/{$course->id}/progress/export")->assertOk();
});

test('a student is forbidden from exporting progress', function () {
    [$tenant, $domain, , $course] = exportFixture();
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($student, 'tenant')->get("http://{$domain}/manage/courses/{$course->id}/progress/export")->assertForbidden();
});

test('a course belonging to another tenant 404s, while the owner\'s own course export succeeds (positive control)', function () {
    [, $domain, $owner, $course] = exportFixture();
    $tenantB = Tenant::factory()->create();
    $tenantB->domains()->create(['domain' => 'tenant-b.coaching.test', 'type' => 'subdomain']);
    $courseB = inTenant($tenantB, fn () => Course::factory()->published()->create());

    // Positive control first: without this, the export route simply not existing yet
    // would 404 for EVERY course id, including this tenant's own, making "another
    // tenant's course 404s" trivially true for the wrong reason.
    $this->actingAs($owner, 'tenant')->get("http://{$domain}/manage/courses/{$course->id}/progress/export")->assertOk();

    freshRequestCycle();

    $this->actingAs($owner, 'tenant')->get("http://{$domain}/manage/courses/{$courseB->id}/progress/export")->assertStatus(404);
});

test('query count stays bounded with 50 enrolled students', function () {
    [$tenant, $domain, $owner, $course] = exportFixture();
    inTenant($tenant, function () use ($course) {
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create(['course_id' => $course->id]);

        for ($i = 0; $i < 50; $i++) {
            $student = User::factory()->student()->create();
            Enrolment::factory()->for($course)->for($student, 'user')->active()->create();
            LessonProgress::factory()->for($lesson)->for($course)->for($student, 'user')->create();
        }
    });

    DB::enableQueryLog();
    $this->actingAs($owner, 'tenant')->get("http://{$domain}/manage/courses/{$course->id}/progress/export")->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(15);
});
