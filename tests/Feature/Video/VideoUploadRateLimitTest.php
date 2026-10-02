<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Phase 11 mini security pass: neither video-upload endpoint was rate limited, so both
 * now carry `throttle:10,60` (same budget as the bulk-import endpoint). Each test below
 * is a positive control plus the negative in one go — every request inside the budget
 * must still succeed, then the first one over it must be 429 — mirroring
 * tests/Feature/Import/PermissionsAndRateLimitTest.php.
 *
 * The fixture gets a uniquely named helper: Pest loads every test file into a single
 * process, so setUpFakeUploadLesson() (StartUploadTest) and setUpFakeVideoForUpload()
 * (FakeUploadControllerTest) cannot be redeclared here.
 */
function videoUploadRateLimitFixture(): array
{
    config(['coaching.video_driver' => 'fake']);
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson, $videoId] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);
        $video = LessonVideo::factory()->for($lesson)->create(['provider_video_id' => (string) Str::uuid()]);

        return [$owner, $lesson, $video->id];
    });

    // See FakeUploadControllerTest::signedFakeUploadUrl() — the signature covers the
    // absolute URL, so the root URL has to be forced onto the tenant's own host first.
    URL::forceRootUrl("http://{$domain}");
    $uploadUrl = URL::signedRoute('lesson-videos.fake-upload', ['lessonVideo' => $videoId]);
    URL::forceRootUrl((string) config('app.url'));

    return [$domain, $owner, $lesson, $uploadUrl];
}

test('starting more than ten video uploads in a minute is rate limited', function () {
    [$domain, $owner, $lesson] = videoUploadRateLimitFixture();
    $url = "http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload";
    $body = ['filename' => 'lecture.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1_000_000];

    // Positive control: every request inside the budget still succeeds.
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($owner, 'tenant')->post($url, $body)->assertOk();
    }

    $this->actingAs($owner, 'tenant')->post($url, $body)->assertStatus(429);
});

test('putting more than ten video files in a minute is rate limited', function () {
    [$domain, $owner, , $uploadUrl] = videoUploadRateLimitFixture();
    $file = fn () => ['file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4')];

    // Positive control: every request inside the budget still succeeds.
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($owner, 'tenant')->post($uploadUrl, $file())->assertNoContent();
    }

    $this->actingAs($owner, 'tenant')->post($uploadUrl, $file())->assertStatus(429);
});
