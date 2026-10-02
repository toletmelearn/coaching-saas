<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PaymentFixtures;

test('a valid PNG screenshot is re-encoded and stored on the private disk under a random filename', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480, 'png', 'holiday photo.png'),
        ])
        ->assertRedirect();

    $payment = inTenant($f['tenant'], fn () => Payment::firstOrFail());

    // Private disk, under the tenant's own prefix, never the client's filename
    expect($payment->screenshot_path)->toStartWith("tenants/{$f['tenant']->id}/payments/");
    expect($payment->screenshot_path)->not->toContain('holiday photo.png');
    expect(Storage::disk('local')->exists($payment->screenshot_path))->toBeTrue();

    // Never reachable as a public path
    expect(file_exists(storage_path('app/public/'.$payment->screenshot_path)))->toBeFalse();
    expect(Storage::disk('public')->exists($payment->screenshot_path))->toBeFalse();

    // Re-encoded: whatever was uploaded, the stored bytes are a plain PNG
    $stored = getimagesizefromstring(Storage::disk('local')->get($payment->screenshot_path));
    expect($stored[2])->toBe(IMAGETYPE_PNG);
});

test('JPG and WebP screenshots are accepted', function ($format) {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480, $format, "screenshot.{$format}"),
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $payment = inTenant($f['tenant'], fn () => Payment::firstOrFail());
    expect(Storage::disk('local')->exists($payment->screenshot_path))->toBeTrue();
})->with(['jpg', 'webp']);

test('an SVG screenshot is rejected', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    // Positive control: a real PNG through the exact same endpoint is accepted
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $svg = PaymentFixtures::file('<svg onload="alert(1)"></svg>', 'screenshot.svg', 'image/svg+xml');

    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => $svg,
        ])
        ->assertSessionHasErrors('screenshot');

    expect(inTenant($f['tenant'], fn () => Payment::count()))->toBe(1);
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');

test('a GIF renamed to .png is rejected before it is decoded', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    // Positive control: a genuine PNG named .png is accepted
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480, 'png', 'screenshot.png'),
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480, 'gif', 'screenshot.png'),
        ])
        ->assertSessionHasErrors('screenshot');

    expect(inTenant($f['tenant'], fn () => Payment::count()))->toBe(1);
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');

test('a PDF is rejected as the wrong MIME type', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    // Positive control: a genuine PNG named .png is accepted
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $pdf = PaymentFixtures::file(
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
        'receipt.pdf',
        'application/pdf'
    );

    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => $pdf,
        ])
        ->assertSessionHasErrors('screenshot');

    expect(inTenant($f['tenant'], fn () => Payment::count()))->toBe(1);
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');

test('an image with a side above 4096px is rejected', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    // Positive control: an image inside the bound is accepted
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(4097, 2000),
        ])
        ->assertSessionHasErrors('screenshot');

    expect(inTenant($f['tenant'], fn () => Payment::count()))->toBe(1);
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');

test('an image within the per-side limit but over 16 megapixels is rejected', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    // Positive control: an image inside both bounds is accepted
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(inTenant($f['tenant'], fn () => Payment::count()))->toBe(1);

    // 4096 x 4096 = 16,777,216 px: every side is inside the bound, the total is not.
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(4096, 4096),
        ])
        ->assertSessionHasErrors('screenshot');

    expect(inTenant($f['tenant'], fn () => Payment::count()))->toBe(1);
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');

test('only one payment can be pending per enrolment', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    // Positive control: the first submission is accepted
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(inTenant($f['tenant'], fn () => Payment::count()))->toBe(1);

    // Negative: a second one while the first still awaits review is refused
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ])
        ->assertSessionHasErrors('screenshot');

    expect(inTenant($f['tenant'], fn () => Payment::count()))->toBe(1);
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');

test('a rejected payment can be replaced by a new submission', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    PaymentFixtures::payment($f['tenant'], $f['enrolment'], [
        'status' => PaymentStatus::Rejected,
        'rejection_reason' => 'Screenshot unreadable',
        'reviewed_at' => now(),
    ]);

    expect(inTenant($f['tenant'], fn () => Payment::count()))->toBe(1);

    // Positive control: a rejection does not lock the student out — they may resubmit
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(inTenant($f['tenant'], fn () => Payment::count()))->toBe(2);
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');

test('the screenshot upload endpoint is rate limited to ten per hour', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    $post = fn () => $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
        ]);

    // Positive control: the first nine attempts inside the window are served normally
    for ($attempt = 1; $attempt <= 9; $attempt++) {
        expect($post()->status())->not->toBe(429);
    }

    // The tenth is the last one allowed
    expect($post()->status())->not->toBe(429);

    // The eleventh is over the allowance
    $post()->assertStatus(429);
})->skip(fn () => ! extension_loaded('gd'), 'GD not available');
