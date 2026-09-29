<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test("enrolled student can open a paid lesson's PDF; non-enrolled student gets 403; guest gets redirected", function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $attachment = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]);

        return LessonAttachment::factory()->for($lesson)->create();
    });
    $lesson = inTenant($tenant, fn () => $attachment->lesson);
    $course = inTenant($tenant, fn () => $lesson->course);

    $url = "http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}/attachments/{$attachment->id}";

    $guestResponse = $this->get($url);
    $guestResponse->assertRedirect("http://{$domain}/login");

    $nonEnrolledStudent = inTenant($tenant, fn () => User::factory()->student()->create());
    $this->actingAs($nonEnrolledStudent, 'tenant')->get($url)->assertForbidden();

    $enrolledStudent = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });

    $response = $this->actingAs($enrolledStudent, 'tenant')->get($url);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('attachment response headers are correct, including for a filename with quotes and non-ASCII characters', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    $originalName = 'Notes "Ch 1" – भौतिकी.pdf';

    [$course, $lesson, $attachment] = inTenant($tenant, function () use ($originalName) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]);
        $attachment = LessonAttachment::factory()->for($lesson)->create(['original_name' => $originalName]);

        return [$course, $lesson, $attachment];
    });

    $student = inTenant($tenant, function () use ($course) {
        $student = User::factory()->student()->create();
        Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

        return $student;
    });

    $response = $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/courses/{$course->slug}/lessons/{$lesson->id}/attachments/{$attachment->id}");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toStartWith('inline;');
    // RFC 5987's encoding token is case-insensitive; Symfony emits it lowercase.
    expect(strtolower($disposition))->toContain("filename*=utf-8''");
    // A header value must not contain a raw, unescaped double quote breaking out of the
    // quoted filename parameter — a backslash-escaped quote (\") inside it is valid
    // RFC 6266 quoted-string syntax, which is what a literal quote in the name becomes.
    expect(preg_match('/^filename="(?:[^"\\\\]|\\\\.)*"$/', explode('; ', $disposition)[1]))->toBe(1);
});

test('the stored attachment file is not reachable via any public URL', function () {
    $tenant = Tenant::factory()->create();

    $attachment = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]);

        return LessonAttachment::factory()->for($lesson)->create();
    });

    // Positive control: the file genuinely exists on its configured (private) disk
    expect(Storage::disk($attachment->disk)->exists($attachment->path))->toBeTrue();

    // Negative: the private disk is never the public disk, and there is no symlink
    // exposing storage/app/private under the public webroot.
    expect($attachment->disk)->not->toBe('public');
    expect(is_link(public_path('storage')))->toBeFalse();
});

test('non-PDF upload is rejected', function () {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]);

        return [$owner, $lesson];
    });

    // Positive control: uploading a real PDF succeeds
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/attachments", [
            'file' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/attachments", [
            'file' => UploadedFile::fake()->image('notes.jpg'),
        ]);

    $response->assertSessionHasErrors('file');
});

test('oversize PDF upload is rejected', function () {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]);

        return [$owner, $lesson];
    });

    $maxKb = config('coaching.max_attachment_mb', 20) * 1024;

    // Positive control: a PDF under the size limit succeeds
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/attachments", [
            'file' => UploadedFile::fake()->create('small.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/manage/lessons/{$lesson->id}/attachments", [
            'file' => UploadedFile::fake()->create('huge.pdf', $maxKb + 100, 'application/pdf'),
        ]);

    $response->assertSessionHasErrors('file');
});

test('deleting a lesson removes its attachment files from disk', function () {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $lesson, $attachment] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->published()->create([
            'course_id' => $course->id,
            'is_free_preview' => false,
        ]);
        $attachment = LessonAttachment::factory()->for($lesson)->create();
        Storage::disk($attachment->disk)->put($attachment->path, 'fake-pdf-bytes');

        return [$owner, $lesson, $attachment];
    });

    // Positive control: the file exists before deletion
    expect(Storage::disk($attachment->disk)->exists($attachment->path))->toBeTrue();

    $this->actingAs($owner, 'tenant')
        ->delete("http://{$domain}/manage/lessons/{$lesson->id}")
        ->assertRedirect();

    expect(Storage::disk($attachment->disk)->exists($attachment->path))->toBeFalse();
});
