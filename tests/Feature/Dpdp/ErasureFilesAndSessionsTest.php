<?php

use App\Enums\UserStatus;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConsentFixtures;

/**
 * Phase 16 §2 — erasure removes payment screenshot files from the private disk after the
 * commit, disables the student, and revokes their sessions, devices and remember token.
 */
function p16ErasureScreenshot(array $f): string
{
    $payment = inTenant($f['tenant'], fn () => Payment::findOrFail($f['payment']->id));
    Storage::fake('local');
    Storage::disk('local')->put($payment->screenshot_path, 'not-really-a-png-but-a-file');

    return $payment->screenshot_path;
}

function p16EraseRequest($t, array $f): TestResponse
{
    return $t->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), [
            'confirmation' => $f['student']->name,
        ]);
}

test('erasure deletes each payment screenshot file from the private disk (positive control: file exists before)', function () {
    $f = ConsentFixtures::erasureSetup();
    $path = p16ErasureScreenshot($f);

    // Positive control: the file really is on the disk before erasure.
    Storage::disk('local')->assertExists($path);

    p16EraseRequest($this, $f)->assertRedirect();

    Storage::disk('local')->assertMissing($path);
});

test('erasure sets the student status to disabled (positive control: status is active before)', function () {
    $f = ConsentFixtures::erasureSetup();
    p16ErasureScreenshot($f);

    expect($f['student']->status)->toBe(UserStatus::Active);

    p16EraseRequest($this, $f)->assertRedirect();

    $student = inTenant($f['tenant'], fn () => User::find($f['student']->id));
    expect($student->status)->toBe(UserStatus::Disabled);
});

test('after erasure the old session is redirected to login and the devices and remember token are revoked (positive control: session worked before)', function () {
    $f = ConsentFixtures::erasureSetup();
    p16ErasureScreenshot($f);

    // Positive control: the student's session works before erasure.
    $this->actingAs($f['student'], 'tenant')
        ->get(ConsentFixtures::dataUrl($f['domain'], $f['student']))
        ->assertStatus(403);
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/dashboard")
        ->assertOk();

    p16EraseRequest($this, $f)->assertRedirect();

    freshRequestCycle();

    // The old session must now be refused.
    $this->actingAs($f['student'], 'tenant')
        ->get("http://{$f['domain']}/dashboard")
        ->assertRedirect("http://{$f['domain']}/login");

    inTenant($f['tenant'], function () use ($f) {
        expect(UserDevice::where('user_id', $f['student']->id)->count())->toBe(0);
        expect(User::find($f['student']->id)->remember_token)->toBeNull();
    });
});
