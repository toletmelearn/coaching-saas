<?php

use App\Models\Payment;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PaymentFixtures;

/**
 * The signed URL is generated against the tenant's own host (see
 * PaymentFixtures::signedScreenshotUrl) and then re-requested from that host, exactly
 * as the real controller flow does — the signature covers the absolute URL.
 */
function screenshotPathFor(string $domain, int|string $paymentId, DateTimeInterface $expiration): string
{
    $url = PaymentFixtures::signedScreenshotUrl($domain, $paymentId, $expiration);

    return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
}

function storeScreenshotFor(Payment $payment): void
{
    $upload = PaymentFixtures::image(640, 480);
    Storage::disk('local')->put($payment->screenshot_path, file_get_contents($upload->getRealPath()));
}

test('a signed screenshot URL serves the image as image/png with nosniff', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    storeScreenshotFor($payment);

    $path = screenshotPathFor($f['domain'], $payment->id, now()->addMinutes(10));

    $response = $this->actingAs($f['student'], 'tenant')->get("http://{$f['domain']}{$path}");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/png');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

test('an unsigned screenshot URL is forbidden', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    storeScreenshotFor($payment);

    // Positive control: the properly signed URL serves the file
    $signed = screenshotPathFor($f['domain'], $payment->id, now()->addMinutes(10));

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}{$signed}")
        ->assertOk();

    // Negative: the same file with the signature stripped is refused
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/payments/{$payment->id}/screenshot")
        ->assertForbidden();

    // Negative: a tampered signature is refused too
    $tampered = preg_replace('/signature=[^&]+/', 'signature='.str_repeat('0', 64), $signed);

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}{$tampered}")
        ->assertForbidden();
});

test('an expired screenshot URL is forbidden', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    storeScreenshotFor($payment);

    // Positive control: a URL that has not expired yet serves the file
    $fresh = screenshotPathFor($f['domain'], $payment->id, now()->addMinutes(10));

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}{$fresh}")
        ->assertOk();

    // Negative: the identical URL, expired, is refused
    $expired = screenshotPathFor($f['domain'], $payment->id, now()->subMinute());

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}{$expired}")
        ->assertForbidden();
});

test("another student's screenshot URL is forbidden", function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup();

    $other = PaymentFixtures::extraEnrolment($f['tenant'], $f['course']);
    $mine = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    $theirs = PaymentFixtures::payment($f['tenant'], $other['enrolment']);

    storeScreenshotFor($mine);
    storeScreenshotFor($theirs);

    // Positive control: the student who submitted it can open their own screenshot
    $own = screenshotPathFor($f['domain'], $mine->id, now()->addMinutes(10));

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}{$own}")
        ->assertOk();

    // Negative: a perfectly valid signature over someone else's payment is still refused
    $someoneElses = screenshotPathFor($f['domain'], $theirs->id, now()->addMinutes(10));

    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}{$someoneElses}")
        ->assertForbidden();
});

test('the private storage path of a screenshot never appears in rendered HTML', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup();

    $payment = PaymentFixtures::payment($f['tenant'], $f['enrolment']);
    storeScreenshotFor($payment);

    // Positive control: the owner's review page really does surface a screenshot URL.
    $review = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/payments/{$payment->id}");

    $review->assertOk();
    $review->assertSee("/payments/{$payment->id}/screenshot", false);

    // ...but never the private-disk path it is served from. This is the Phase 5 rule
    // ("the raw secret never reaches HTML") applied to the Phase 10 asset: the path is
    // an implementation detail of local private storage, and leaking it would tell an
    // attacker exactly where to look if a disk ever became reachable.
    $review->assertDontSee($payment->screenshot_path, false);

    // The student's own payment page shows status and rejection reasons, never a path.
    $studentPage = $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment");

    $studentPage->assertOk();
    $studentPage->assertDontSee($payment->screenshot_path, false);
});
