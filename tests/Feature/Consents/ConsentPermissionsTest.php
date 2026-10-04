<?php

use App\Models\User;
use Tests\Support\ConsentFixtures;

// === Permissions ===

test('the consents and data screens are open to the owner and to staff', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->get(ConsentFixtures::consentsUrl($f['domain'], $f['student']))
        ->assertOk();

    $this->actingAs($f['staff'], 'tenant')
        ->get(ConsentFixtures::consentsUrl($f['domain'], $f['student']))
        ->assertOk();

    $export = $this->actingAs($f['staff'], 'tenant')
        ->get(ConsentFixtures::exportUrl($f['domain'], $f['student']));

    $export->assertOk();

    expect($export->headers->get('content-disposition'))
        ->not->toBeNull()
        ->toContain('attachment');
});

test('a student cannot view consent or data screens, not even their own', function () {
    $f = ConsentFixtures::setup();
    $otherStudent = inTenant($f['tenant'], fn () => User::factory()->student()->create());
    $consent = ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'course_delivery');

    // Positive control: the owner's identical request succeeds, so the 403s
    // below are authorization refusals rather than a missing route.
    $this->actingAs($f['owner'], 'tenant')
        ->get(ConsentFixtures::consentsUrl($f['domain'], $f['student']))
        ->assertOk();

    $this->actingAs($f['student'], 'tenant')
        ->get(ConsentFixtures::consentsUrl($f['domain'], $f['student']))
        ->assertForbidden();

    $this->actingAs($f['student'], 'tenant')
        ->get(ConsentFixtures::consentsUrl($f['domain'], $otherStudent))
        ->assertForbidden();

    $this->actingAs($f['student'], 'tenant')
        ->get(ConsentFixtures::dataUrl($f['domain'], $f['student']))
        ->assertForbidden();

    $this->actingAs($f['student'], 'tenant')
        ->get(ConsentFixtures::exportUrl($f['domain'], $f['student']))
        ->assertForbidden();

    $this->actingAs($f['student'], 'tenant')
        ->post(ConsentFixtures::withdrawUrl($f['domain'], $f['student'], $consent->id), [
            'reason' => 'I changed my mind',
        ])
        ->assertForbidden();
});

// === Cross-tenant isolation ===

test('consent and data screens 404 across tenants', function () {
    $a = ConsentFixtures::setup();
    $b = ConsentFixtures::setup('tenant-b.coaching.test');

    // Positive control: this owner reaches their own student's screens, so the
    // 404s below are tenant isolation, not a broken URL.
    $this->actingAs($a['owner'], 'tenant')
        ->get(ConsentFixtures::consentsUrl($a['domain'], $a['student']))
        ->assertOk();

    $this->actingAs($a['owner'], 'tenant')
        ->get(ConsentFixtures::consentsUrl($a['domain'], $b['student']))
        ->assertNotFound();

    $this->actingAs($a['owner'], 'tenant')
        ->get(ConsentFixtures::exportUrl($a['domain'], $b['student']))
        ->assertNotFound();

    $this->actingAs($a['owner'], 'tenant')
        ->delete(ConsentFixtures::dataUrl($a['domain'], $b['student']), [
            'confirmation' => $b['student']->name,
        ])
        ->assertNotFound();

    // The cross-tenant erasure attempt changed nothing.
    $bStudent = inTenant($b['tenant'], fn () => User::find($b['student']->id));

    expect($bStudent->name)->not->toBe(ConsentFixtures::ERASED_NAME)
        ->and($bStudent->email)->not->toBeNull();
});
