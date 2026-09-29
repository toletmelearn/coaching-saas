<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

function setUpFakeUploadLesson(): array
{
    config(['coaching.video_driver' => 'fake']);
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return [$owner, $lesson];
    });

    return [$tenant, $domain, $owner, $lesson];
}

test('owner can start an upload', function () {
    [, $domain, $owner, $lesson] = setUpFakeUploadLesson();

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'lecture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ])->assertOk();
});

test('staff can start an upload', function () {
    [$tenant, $domain, , $lesson] = setUpFakeUploadLesson();
    $staff = inTenant($tenant, fn () => User::factory()->staff()->create());

    $this->actingAs($staff, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'lecture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ])->assertOk();
});

test('student cannot start an upload', function () {
    [$tenant, $domain, , $lesson] = setUpFakeUploadLesson();
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    $this->actingAs($student, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'lecture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ])->assertForbidden();
});

test('starting an upload for another tenant\'s lesson 404s', function () {
    [, $domainA, $ownerA, $lessonA] = setUpFakeUploadLesson();
    $tenantB = Tenant::factory()->create();
    $lessonB = inTenant($tenantB, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);
    });

    // Positive control: the same owner starting an upload for their OWN tenant's lesson
    // succeeds (200) — proves the negative assertion below is real tenant scoping, not
    // just "the route doesn't exist yet" producing a 404 for every request.
    $this->actingAs($ownerA, 'tenant')
        ->post("http://{$domainA}/manage/lessons/{$lessonA->id}/video/start-upload", [
            'filename' => 'own-lecture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ])->assertOk();

    $this->actingAs($ownerA, 'tenant')
        ->post("http://{$domainA}/manage/lessons/{$lessonB->id}/video/start-upload", [
            'filename' => 'lecture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ])->assertNotFound();
});

test('wrong file type is rejected with a teacher-language message', function () {
    [, $domain, $owner, $lesson] = setUpFakeUploadLesson();

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'lecture.exe',
            'mime_type' => 'application/x-msdownload',
            'size_bytes' => 1_000_000,
        ]);

    $response->assertSessionHasErrors('video');
    expect(session('errors')?->first('video'))->toBe(__('lessons.video.unsupported_file_type'));
});

test('oversize file is rejected with a teacher-language message', function () {
    [, $domain, $owner, $lesson] = setUpFakeUploadLesson();
    $maxBytes = config('coaching.max_video_mb', 2048) * 1024 * 1024;

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'lecture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => $maxBytes + 1,
        ]);

    $response->assertSessionHasErrors('video');
    expect(session('errors')?->first('video'))->toBe(__('lessons.video.file_too_large'));
});

test('a lesson with a YouTube video rejects the start-upload request', function () {
    [$tenant, $domain, $owner, $lesson] = setUpFakeUploadLesson();
    inTenant($tenant, fn () => $lesson->forceFill([
        'is_free_preview' => true,
        'youtube_video_id' => 'dQw4w9WgXcQ',
    ])->save());

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'lecture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ]);

    $response->assertSessionHasErrors('video');
});
