<?php

use App\Models\Enrolment;
use App\Models\User;
use Tests\Support\ConsentFixtures;

// === Data export ===

test('the owner can download a student data export as a JSON file containing every listed collection', function () {
    $f = ConsentFixtures::erasureSetup();
    ConsentFixtures::forceGuardian($f['tenant'], $f['student']);
    ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'course_delivery');

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get(ConsentFixtures::exportUrl($f['domain'], $f['student']));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))
        ->not->toBeNull()
        ->toContain('attachment')
        ->and($response->headers->get('content-type'))
        ->toContain('application/json');

    $data = $response->json();

    expect($data)
        ->toHaveKeys(['user', 'enrolments', 'progress', 'devices', 'consents', 'payments'])
        ->and($data['user']['id'])->toBe($f['student']->id)
        ->and($data['user']['email'])->toBe($f['student']->email)
        ->and($data['user']['guardian_name'])->toBe('Meera Rao')
        ->and($data['user']['guardian_phone'])->toBe('9876500001')
        ->and($data['enrolments'])->toHaveCount(1)
        ->and($data['progress'])->toHaveCount(1)
        ->and($data['devices'])->toHaveCount(1)
        ->and($data['consents'])->toHaveCount(1)
        ->and($data['payments'])->toHaveCount(1);
});

test('a student export never contains another student of the same tenant', function () {
    $f = ConsentFixtures::erasureSetup();

    [$other] = inTenant($f['tenant'], function () use ($f) {
        $other = User::factory()->student()->create([
            'name' => 'Zed Otherstudent',
            'phone' => '9876599999',
        ]);
        Enrolment::factory()->for($f['course'])->for($other, 'user')->active()->create();

        return [$other];
    });
    ConsentFixtures::progress($f['tenant'], $f['course'], $f['lesson'], $other);
    ConsentFixtures::consentRow($f['tenant'], $other, $f['owner'], 'course_delivery');

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get(ConsentFixtures::exportUrl($f['domain'], $f['student']));

    $response->assertOk();

    $data = $response->json();
    $encoded = json_encode($data);

    expect($data['user']['id'])->toBe($f['student']->id)
        ->and($encoded)->not->toContain('Zed Otherstudent')
        ->and($encoded)->not->toContain('9876599999');
});
