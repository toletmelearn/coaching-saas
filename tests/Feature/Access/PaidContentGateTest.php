<?php

use App\Enums\PaymentStatus;
use App\Models\Consent;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\LessonAttachment;
use App\Models\LessonVideo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\LiveClassFixtures;
use Tests\Support\PaymentFixtures;

/**
 * Batch 1 (B): every content path applies the same rule as the lesson page: a valid enrolment, an
 * approved payment on a paid course, and no withdrawn course-delivery consent. Staff are exempt,
 * free courses and free-preview lessons keep their existing rules.
 *
 * Negative tests (X is refused) must fail on the old code; each has a positive control that passes.
 */
function b1Setup(string $paymentStatus, int $feePaise = 49900): array
{
    Storage::fake('local');
    $f = LiveClassFixtures::setup();

    inTenant($f['tenant'], fn () => Course::query()->whereKey($f['course']->id)->update(['fee_paise' => $feePaise]));

    $enrolment = inTenant($f['tenant'], fn () => Enrolment::query()->where('user_id', $f['student']->id)->firstOrFail());

    if ($paymentStatus !== 'none') {
        PaymentFixtures::payment($f['tenant'], $enrolment, ['status' => PaymentStatus::from($paymentStatus)]);
    }

    return $f + ['enrolment' => $enrolment];
}

function b1Withdraw(array $f): void
{
    inTenant($f['tenant'], function () use ($f) {
        $consent = new Consent;
        $consent->forceFill([
            'tenant_id' => $f['tenant']->id,
            'user_id' => $f['student']->id,
            'purpose' => 'course_delivery',
            'method' => 'owner_attested',
            'recorded_by' => $f['owner']->id,
            'notice_version' => Consent::NOTICE_VERSION,
            'granted_at' => now()->subDay(),
            'withdrawn_at' => now(),
        ])->save();
    });
}

function b1Attachment(array $f): LessonAttachment
{
    return inTenant($f['tenant'], fn () => LessonAttachment::factory()->create([
        'tenant_id' => $f['tenant']->id,
        'lesson_id' => $f['lesson']->id,
        'disk' => 'local',
    ]));
}

function b1AttachmentUrl(array $f, LessonAttachment $a): string
{
    return "http://{$f['domain']}/courses/{$f['course']->slug}/lessons/{$f['lesson']->id}/attachments/{$a->id}";
}

function b1Heartbeat(array $f): string
{
    return "http://{$f['domain']}/lessons/{$f['lesson']->id}/progress";
}

function b1Video(array $f): LessonVideo
{
    return inTenant($f['tenant'], function () use ($f) {
        $v = LessonVideo::factory()->for($f['lesson'])->create(['provider_video_id' => 'b1-video']);
        Storage::disk('local')->put($v->storage_path, 'fake-video');

        return $v;
    });
}

function b1VideoUrl(array $f, LessonVideo $video): string
{
    // Sign against the tenant host itself: rewriting the host after signing would break the signature.
    URL::forceRootUrl("http://{$f['domain']}");
    $signed = URL::temporarySignedRoute('lesson-videos.stream', now()->addMinutes(10), ['lessonVideo' => $video->id]);
    URL::forceRootUrl(null);

    return $signed;
}

function b1Live(array $f): object
{
    return LiveClassFixtures::liveClass($f['course'], $f['owner'], ['starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
}

// ---- attachment download -------------------------------------------------------------------

test('attachment download is refused for a payment-pending student', function () {
    $f = b1Setup('pending');
    $this->actingAs($f['student'], 'tenant')->get(b1AttachmentUrl($f, b1Attachment($f)))->assertForbidden();
});

test('positive control: attachment download works for an approved-payment student', function () {
    $f = b1Setup('approved');
    $this->actingAs($f['student'], 'tenant')->get(b1AttachmentUrl($f, b1Attachment($f)))->assertOk();
});

test('attachment download is refused for a payment-rejected student', function () {
    $f = b1Setup('rejected');
    $this->actingAs($f['student'], 'tenant')->get(b1AttachmentUrl($f, b1Attachment($f)))->assertForbidden();
});

test('attachment download is refused for a consent-withdrawn student with an approved payment', function () {
    $f = b1Setup('approved');
    b1Withdraw($f);
    $this->actingAs($f['student'], 'tenant')->get(b1AttachmentUrl($f, b1Attachment($f)))->assertForbidden();
});

test('positive control: staff download the attachment with no payment at all', function () {
    $f = b1Setup('none');
    $this->actingAs($f['staff'], 'tenant')->get(b1AttachmentUrl($f, b1Attachment($f)))->assertOk();
});

test('positive control: a free course (fee 0) needs no payment for the attachment', function () {
    $f = b1Setup('none', 0);
    $this->actingAs($f['student'], 'tenant')->get(b1AttachmentUrl($f, b1Attachment($f)))->assertOk();
});

test('positive control: a free-preview lesson is open to a guest with no enrolment', function () {
    $f = b1Setup('none');
    inTenant($f['tenant'], fn () => $f['lesson']->forceFill(['is_free_preview' => true])->save());
    $this->get(b1AttachmentUrl($f, b1Attachment($f)))->assertOk();
});

// ---- progress heartbeat --------------------------------------------------------------------

test('lesson progress heartbeat is refused for a payment-pending student', function () {
    $f = b1Setup('pending');
    $this->actingAs($f['student'], 'tenant')
        ->post(b1Heartbeat($f), ['position' => 30, 'duration' => 60, 'played' => 30])
        ->assertForbidden();
});

test('positive control: heartbeat is accepted once the payment is approved', function () {
    $f = b1Setup('approved');
    $this->actingAs($f['student'], 'tenant')
        ->post(b1Heartbeat($f), ['position' => 30, 'duration' => 60, 'played' => 30])
        ->assertSuccessful();
});

test('lesson progress heartbeat is refused for a consent-withdrawn student with an approved payment', function () {
    $f = b1Setup('approved');
    b1Withdraw($f);
    $this->actingAs($f['student'], 'tenant')
        ->post(b1Heartbeat($f), ['position' => 30, 'duration' => 60, 'played' => 30])
        ->assertForbidden();
});

// ---- video stream --------------------------------------------------------------------------

test('lesson video stream is refused for a payment-pending student', function () {
    $f = b1Setup('pending');
    $video = b1Video($f);
    $this->actingAs($f['student'], 'tenant')->get(b1VideoUrl($f, $video))->assertForbidden();
});

test('positive control: the video stream is served to an approved-payment student', function () {
    $f = b1Setup('approved');
    $video = b1Video($f);
    $this->actingAs($f['student'], 'tenant')->get(b1VideoUrl($f, $video))->assertOk();
});

test('lesson video stream is refused for a consent-withdrawn student with an approved payment', function () {
    $f = b1Setup('approved');
    b1Withdraw($f);
    $video = b1Video($f);
    $this->actingAs($f['student'], 'tenant')->get(b1VideoUrl($f, $video))->assertForbidden();
});

// ---- live class page and join --------------------------------------------------------------

test('live-class page is refused for a payment-pending student', function () {
    $f = b1Setup('pending');
    $class = b1Live($f);
    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/live-classes/{$class->id}")->assertForbidden();
});

test('positive control: the live-class page is shown to an approved-payment student', function () {
    $f = b1Setup('approved');
    $class = b1Live($f);
    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/live-classes/{$class->id}")->assertOk();
});

test('live-class page is refused for a consent-withdrawn student with an approved payment', function () {
    $f = b1Setup('approved');
    b1Withdraw($f);
    $class = b1Live($f);
    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/live-classes/{$class->id}")->assertForbidden();
});

test('live-class join does not redirect a payment-pending student to the JaaS room', function () {
    config(['coaching.live_classes_enabled' => true]);
    $f = b1Setup('pending');
    $class = b1Live($f);

    $response = $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/live-classes/{$class->id}/join");

    expect((string) $response->headers->get('Location'))->not->toContain('8x8.vc');
});

test('positive control: live-class join reaches the JaaS room for an approved-payment student', function () {
    config(['coaching.live_classes_enabled' => true]);
    $f = b1Setup('approved');
    $class = b1Live($f);

    $response = $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/live-classes/{$class->id}/join");

    expect((string) $response->headers->get('Location'))->toContain('8x8.vc');
});

test('live-class join is refused for a consent-withdrawn student with an approved payment', function () {
    config(['coaching.live_classes_enabled' => true]);
    $f = b1Setup('approved');
    b1Withdraw($f);
    $class = b1Live($f);

    $response = $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/live-classes/{$class->id}/join");

    expect((string) $response->headers->get('Location'))->not->toContain('8x8.vc');
});

// ---- dashboard "Continue" link -------------------------------------------------------------

test('the dashboard Continue link does not point a payment-pending student at a paid lesson', function () {
    $f = b1Setup('pending');
    $lessonPath = "/courses/{$f['course']->slug}/lessons/{$f['lesson']->id}";

    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard")->assertDontSee($lessonPath, false);
});

test('positive control: the dashboard Continue link points an approved-payment student at the lesson', function () {
    $f = b1Setup('approved');
    $lessonPath = "/courses/{$f['course']->slug}/lessons/{$f['lesson']->id}";

    $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}/dashboard")->assertSee($lessonPath, false);
});
