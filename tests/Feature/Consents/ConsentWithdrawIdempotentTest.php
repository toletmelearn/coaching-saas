<?php

use App\Models\Consent;
use Tests\Support\ConsentFixtures;

/**
 * A repeat withdrawal leaves the original withdrawn_at and reason untouched: the ledger records the
 * first withdrawal, not the last one.
 */
test('a repeat withdraw POST leaves withdrawn_at and the reason unchanged (positive control: the first withdrawal is recorded)', function () {
    $f = ConsentFixtures::setup();
    $consent = ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'communication');
    $url = ConsentFixtures::withdrawUrl($f['domain'], $f['student'], $consent->id);

    $this->actingAs($f['owner'], 'tenant')->post($url, ['reason' => 'first reason'])->assertRedirect();

    $first = inTenant($f['tenant'], fn () => Consent::find($consent->id));
    expect($first->withdrawn_at)->not->toBeNull()
        ->and($first->withdrawn_reason)->toBe('first reason');

    $this->travel(2)->minutes();
    freshRequestCycle();
    $this->actingAs($f['owner'], 'tenant')->post($url, ['reason' => 'second reason'])->assertRedirect();

    $second = inTenant($f['tenant'], fn () => Consent::find($consent->id));
    expect($second->withdrawn_at->equalTo($first->withdrawn_at))->toBeTrue()
        ->and($second->withdrawn_reason)->toBe('first reason');
});
