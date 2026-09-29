<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Simulates the state right after start-upload: an awaiting_upload LessonVideo row with
 * its provider_video_id already assigned (FakeVideoProvider::createProviderVideo()), so
 * the signed upload URL this controller actually receives can be exercised directly.
 */
function setUpFakeVideoForUpload(): array
{
    config(['coaching.video_driver' => 'fake']);
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    [$owner, $videoId] = inTenant($tenant, function () {
        $owner = User::factory()->owner()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);
        $video = LessonVideo::factory()->for($lesson)->create(['provider_video_id' => (string) Str::uuid()]);

        return [$owner, $video->id];
    });

    return [$tenant, $domain, $owner, $videoId];
}

/**
 * See tests/Feature/Video/FakeStreamRouteTest.php's signedStreamUrl() for why the root
 * URL must be forced to the tenant's own domain before generating.
 */
function signedFakeUploadUrl(string $domain, int $videoId): string
{
    URL::forceRootUrl("http://{$domain}");
    $url = URL::signedRoute('lesson-videos.fake-upload', ['lessonVideo' => $videoId]);
    URL::forceRootUrl((string) config('app.url'));

    return $url;
}

test('a signed URL is required — the plain unsigned path is rejected', function () {
    [, $domain, $owner, $videoId] = setUpFakeVideoForUpload();

    // Positive control: the properly signed URL succeeds.
    $signedUrl = signedFakeUploadUrl($domain, $videoId);
    $signedPath = parse_url($signedUrl, PHP_URL_PATH).'?'.parse_url($signedUrl, PHP_URL_QUERY);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}{$signedPath}", ['file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4')])
        ->assertNoContent();

    $unsignedPath = parse_url($signedUrl, PHP_URL_PATH);

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}{$unsignedPath}", ['file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4')])
        ->assertForbidden();
});

test('owner and staff can upload; a student cannot', function () {
    [$tenant, $domain, $owner, $videoIdForOwner] = setUpFakeVideoForUpload();

    $this->actingAs($owner, 'tenant')
        ->post(signedFakeUploadUrl($domain, $videoIdForOwner), ['file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4')])
        ->assertNoContent();

    [$staff, $videoIdForStaff] = inTenant($tenant, function () {
        $staff = User::factory()->staff()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);
        $video = LessonVideo::factory()->for($lesson)->create(['provider_video_id' => (string) Str::uuid()]);

        return [$staff, $video->id];
    });

    $this->actingAs($staff, 'tenant')
        ->post(signedFakeUploadUrl($domain, $videoIdForStaff), ['file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4')])
        ->assertNoContent();

    [$student, $videoIdForStudent] = inTenant($tenant, function () {
        $student = User::factory()->student()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);
        $video = LessonVideo::factory()->for($lesson)->create(['provider_video_id' => (string) Str::uuid()]);

        return [$student, $video->id];
    });

    $this->actingAs($student, 'tenant')
        ->post(signedFakeUploadUrl($domain, $videoIdForStudent), ['file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4')])
        ->assertForbidden();
});

test('the size limit is enforced on the server, not just at start-upload', function () {
    [$tenant, $domain, $owner, $videoId] = setUpFakeVideoForUpload();
    config(['coaching.max_video_mb' => 1]); // 1MB limit for this test

    // Positive control: a file under the limit succeeds.
    $this->actingAs($owner, 'tenant')
        ->post(signedFakeUploadUrl($domain, $videoId), ['file' => UploadedFile::fake()->create('small.mp4', 500, 'video/mp4')])
        ->assertNoContent();

    // Reuses the same tenant/lesson chain via a fresh video row so the "at most one
    // video per lesson" constraint isn't hit by re-posting to the same row twice.
    $secondVideoId = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return LessonVideo::factory()->for($lesson)->create(['provider_video_id' => (string) Str::uuid()])->id;
    });

    $this->actingAs($owner, 'tenant')
        ->post(signedFakeUploadUrl($domain, $secondVideoId), ['file' => UploadedFile::fake()->create('huge.mp4', 2000, 'video/mp4')])
        ->assertStatus(422);
});

test('the file type is validated from the server-detected content type, not the client-declared one', function () {
    [, $domain, $owner, $videoId] = setUpFakeVideoForUpload();

    // A real temp file whose actual content is plain text, but whose HTTP part claims
    // "video/mp4" — exactly what a malicious client controls. Illuminate\Http\UploadedFile
    // (unlike the Testing\File fake, which just echoes back whatever mime you hand it)
    // computes getMimeType() from the real file content via the system mime-type guesser,
    // while getClientMimeType() stays whatever was declared — so this proves the
    // controller checks the former, not the latter.
    $tmpPath = tempnam(sys_get_temp_dir(), 'phase5-upload-test');
    file_put_contents($tmpPath, str_repeat('this is not a video file, just text', 50));
    $spoofedFile = new UploadedFile($tmpPath, 'lecture.mp4', 'video/mp4', null, true);

    expect($spoofedFile->getClientMimeType())->toBe('video/mp4');
    expect($spoofedFile->getMimeType())->not->toBe('video/mp4'); // sanity: the guesser sees through the declared type

    $response = $this->actingAs($owner, 'tenant')
        ->post(signedFakeUploadUrl($domain, $videoId), ['file' => $spoofedFile]);

    $response->assertStatus(422);

    @unlink($tmpPath);
});

test('an upload exceeding PHP\'s own post_max_size (empty $_FILES) is reported as file-too-large, not a generic error', function () {
    [$tenant, $domain, $owner, $videoId] = setUpFakeVideoForUpload();
    config(['coaching.max_video_mb' => 1]); // 1MB limit for this test

    $signedUrl = signedFakeUploadUrl($domain, $videoId);
    $path = parse_url($signedUrl, PHP_URL_PATH).'?'.parse_url($signedUrl, PHP_URL_QUERY);

    // Simulates PHP silently discarding a too-large body before populating $_FILES —
    // Content-Length is still what the browser sent, well above the configured limit.
    $response = $this->actingAs($owner, 'tenant')
        ->call('POST', "http://{$domain}{$path}", [], [], [], ['CONTENT_LENGTH' => 5 * 1024 * 1024]);

    $response->assertStatus(422);
    expect($response->getContent())->toContain(__('lessons.video.file_too_large'));

    // Positive control: no file and a Content-Length that's NOT over the limit reports
    // the generic "no/unsupported file" message instead, proving the two cases are
    // genuinely distinguished rather than always guessing "too large".
    $secondVideoId = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return LessonVideo::factory()->for($lesson)->create(['provider_video_id' => (string) Str::uuid()])->id;
    });
    $secondSignedUrl = signedFakeUploadUrl($domain, $secondVideoId);
    $secondPath = parse_url($secondSignedUrl, PHP_URL_PATH).'?'.parse_url($secondSignedUrl, PHP_URL_QUERY);

    $secondResponse = $this->actingAs($owner, 'tenant')
        ->call('POST', "http://{$domain}{$secondPath}", [], [], [], ['CONTENT_LENGTH' => 100]);

    $secondResponse->assertStatus(422);
    expect($secondResponse->getContent())->toContain(__('lessons.video.unsupported_file_type'));
});

test('the stored file lives under tenants/{tenant}/videos/{provider_video_id}, never a path derived from the client filename', function () {
    [$tenant, $domain, $owner, $videoId] = setUpFakeVideoForUpload();

    $this->actingAs($owner, 'tenant')
        ->post(signedFakeUploadUrl($domain, $videoId), [
            'file' => UploadedFile::fake()->create('../../etc/passwd-lecture.mp4', 100, 'video/mp4'),
        ])->assertNoContent();

    $video = inTenant($tenant, fn () => LessonVideo::find($videoId));

    expect($video->storage_path)->toStartWith("tenants/{$tenant->id}/videos/")
        ->and($video->storage_path)->not->toContain('passwd')
        ->and($video->storage_path)->not->toContain('..');

    Storage::disk('local')->assertExists($video->storage_path);
});

test('the upload endpoint is refused when APP_ENV=production', function () {
    [$tenant, $domain, $owner, $videoId] = setUpFakeVideoForUpload();
    $signedUrl = signedFakeUploadUrl($domain, $videoId);

    // Positive control: outside production (the default testing env), the same signed
    // URL works.
    $this->actingAs($owner, 'tenant')
        ->post($signedUrl, ['file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4')])
        ->assertNoContent();

    $secondVideoId = inTenant($tenant, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return LessonVideo::factory()->for($lesson)->create(['provider_video_id' => (string) Str::uuid()])->id;
    });
    $secondSignedUrl = signedFakeUploadUrl($domain, $secondVideoId);

    // Setting env to 'production' also (correctly) re-enables real CSRF verification
    // (see FakeUploadCsrfTest.php) — irrelevant to what this test checks, so it's
    // disabled here rather than fabricating a token.
    app()->instance('env', 'production');
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->actingAs($owner, 'tenant')
        ->post($secondSignedUrl, ['file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4')])
        ->assertNotFound();
});

test('another tenant\'s video id 404s, even with an otherwise-valid signature shape', function () {
    [, $domainA, $ownerA, $videoIdA] = setUpFakeVideoForUpload();

    $tenantB = Tenant::factory()->create();
    $domainB = 'tenant-b.coaching.test';
    $tenantB->domains()->create(['domain' => $domainB, 'type' => 'subdomain']);
    $videoIdB = inTenant($tenantB, function () {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($chapter)->create(['course_id' => $course->id]);

        return LessonVideo::factory()->for($lesson)->create(['provider_video_id' => (string) Str::uuid()])->id;
    });

    // Positive control: tenant A's owner can upload to tenant A's own video.
    $this->actingAs($ownerA, 'tenant')
        ->post(signedFakeUploadUrl($domainA, $videoIdA), ['file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4')])
        ->assertNoContent();

    // Negative: tenant B's video id requested on tenant A's domain — route-model binding
    // is scoped to the tenant resolved from the hostname, so it simply doesn't exist there.
    $this->actingAs($ownerA, 'tenant')
        ->post(signedFakeUploadUrl($domainA, $videoIdB), ['file' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4')])
        ->assertNotFound();
});
