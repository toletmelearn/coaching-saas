<?php

use App\Enums\VideoStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;

// === Composite FK / uniqueness ===

test('a lesson video cannot reference a lesson from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $lessonA = inTenant($tenantA, function () {
        $course = Course::factory()->create();
        $chapter = Chapter::factory()->for($course)->create();

        return Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);
    });

    // Positive control: a lesson_video referencing a lesson in its own tenant succeeds
    inTenant($tenantB, function () {
        $course = Course::factory()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        expect(LessonVideo::create([
            'lesson_id' => $lesson->id,
            'provider' => 'fake',
            'original_filename' => 'lecture.mp4',
        ]))->not->toBeNull();
    });

    expect(fn () => inTenant($tenantB, function () use ($lessonA) {
        LessonVideo::create([
            'lesson_id' => $lessonA->id,
            'provider' => 'fake',
            'original_filename' => 'lecture.mp4',
        ]);
    }))->toThrow(QueryException::class);
});

test('a lesson has at most one video row', function () {
    $tenant = Tenant::factory()->create();

    inTenant($tenant, function () {
        $course = Course::factory()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        // Positive control: the first video row for a lesson succeeds
        expect(LessonVideo::create([
            'lesson_id' => $lesson->id,
            'provider' => 'fake',
            'original_filename' => 'lecture.mp4',
        ]))->not->toBeNull();

        expect(fn () => LessonVideo::create([
            'lesson_id' => $lesson->id,
            'provider' => 'fake',
            'original_filename' => 'lecture-2.mp4',
        ]))->toThrow(QueryException::class);
    });
});

// === Mass assignment guards ===

test('lesson video status, provider, provider_video_id, tenant_id and uploaded_by are not mass-assignable', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    inTenant($tenant, function () use ($tenant, $otherTenant) {
        $course = Course::factory()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);
        $owner = User::factory()->owner()->create();

        // Positive control: the approved path (factory state -> forceFill) can set these
        $viaState = LessonVideo::factory()->for($lesson)->ready()->create(['uploaded_by' => $owner->id]);
        expect($viaState->status)->toBe(VideoStatus::Ready)
            ->and($viaState->provider_video_id)->not->toBeNull()
            ->and($viaState->uploaded_by)->toBe($owner->id);

        $otherLesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        // Negative: raw mass-assignment must not set any of these guarded fields
        $viaMassAssignment = LessonVideo::create([
            'lesson_id' => $otherLesson->id,
            'original_filename' => 'lecture.mp4',
            'status' => VideoStatus::Ready->value,
            'provider' => 'bunny',
            'provider_video_id' => 'guessed-guid',
            'tenant_id' => $otherTenant->id,
            'uploaded_by' => $owner->id + 999,
        ]);

        expect($viaMassAssignment->status)->toBe(VideoStatus::AwaitingUpload)
            ->and($viaMassAssignment->provider)->toBe('fake')
            ->and($viaMassAssignment->provider_video_id)->toBeNull()
            ->and($viaMassAssignment->tenant_id)->toBe($tenant->id)
            ->and($viaMassAssignment->uploaded_by)->toBeNull();
    });
});

// === YouTube / protected-video mutual exclusivity ===

test('a lesson with a protected video rejects a YouTube URL', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
        ]);
        LessonVideo::factory()->for($lesson)->create();

        return [$owner, $lesson];
    });

    $response = $this->actingAs($owner, 'tenant')
        ->patch("http://{$domain}/manage/lessons/{$lesson->id}", [
            'title' => 'Has a video already',
            'is_free_preview' => true,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

    $response->assertSessionHasErrors('youtube_url');
});

test('a lesson with a YouTube video rejects a protected-video upload attempt', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create([
            'course_id' => $course->id,
            'is_free_preview' => true,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);

        return [$owner, $lesson];
    });

    // Positive control: a lesson without a YouTube video can start an upload
    inTenant($tenant, function () use ($owner) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $freeLesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        $this->actingAs($owner, 'tenant')
            ->post("http://tenant-a.coaching.test/manage/lessons/{$freeLesson->id}/video/start-upload", [
                'filename' => 'lecture.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => 1_000_000,
            ])->assertOk();
    });

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/video/start-upload", [
            'filename' => 'lecture.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1_000_000,
        ]);

    $response->assertSessionHasErrors('video');

    inTenant($tenant, fn () => expect(LessonVideo::where('lesson_id', $lesson->id)->exists())->toBeFalse());
});
