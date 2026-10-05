<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ConsentFixtures;

/**
 * An erased student's row keeps the @removed.invalid sentinel email. Such a row must never be
 * re-enabled or given a password reset. Erasure also replaces the password with an unusable
 * random value, so nothing guessable ever logs the erased row in.
 */
test('an erased student cannot be re-enabled (positive control: a normal disabled student can)', function () {
    $f = ConsentFixtures::erasureSetup();
    $normal = inTenant($f['tenant'], fn () => User::factory()->student()->disabled()->create());

    // Positive control: a normal disabled student is re-enabled.
    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users/{$normal->id}/enable")
        ->assertRedirect();
    expect(inTenant($f['tenant'], fn () => User::find($normal->id))->status->value)->toBe('active');

    $erased = $f['student'];
    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $erased), ['confirmation' => $erased->name])
        ->assertRedirect();

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users/{$erased->id}/enable")
        ->assertForbidden();
});

test('an erased student cannot be given a password reset (positive control: a normal student can)', function () {
    $f = ConsentFixtures::erasureSetup();
    $normal = inTenant($f['tenant'], fn () => User::factory()->student()->create());

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users/{$normal->id}/reset-password")
        ->assertRedirect();

    $erased = $f['student'];
    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $erased), ['confirmation' => $erased->name])
        ->assertRedirect();

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users/{$erased->id}/reset-password")
        ->assertForbidden();
});

test('erasure replaces the password with an unusable random value (positive control: the old password worked)', function () {
    $f = ConsentFixtures::erasureSetup();

    $before = inTenant($f['tenant'], fn () => User::find($f['student']->id)->password);
    expect(Hash::check('password', $before))->toBeTrue();

    $this->actingAs($f['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($f['domain'], $f['student']), ['confirmation' => $f['student']->name])
        ->assertRedirect();

    $after = inTenant($f['tenant'], fn () => User::find($f['student']->id)->password);
    expect($after)->not->toBe($before)
        ->and(Hash::check('password', $after))->toBeFalse()
        ->and(Hash::check('', $after))->toBeFalse();
});
