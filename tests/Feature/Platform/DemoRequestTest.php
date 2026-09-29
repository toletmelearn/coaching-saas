<?php

use App\Enums\DemoRequestStatus;
use App\Models\DemoRequest;
use App\Models\Tenant;

function validDemoRequestPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Priya Sharma',
        'phone' => '9876543210',
        'email' => 'priya@example.com',
        'institute_name' => 'Bright Future Academy',
        'city' => 'Pune',
        'message' => 'Interested in the video feature.',
    ], $overrides);
}

test('a valid demo request is stored, with the phone normalised', function () {
    $response = $this->post('http://coaching.test/demo-requests', validDemoRequestPayload(['phone' => '+91 98765 43210']));

    $response->assertRedirect();

    $demoRequest = DemoRequest::query()->where('name', 'Priya Sharma')->firstOrFail();

    expect($demoRequest->phone)->toBe('9876543210')
        ->and($demoRequest->email)->toBe('priya@example.com')
        ->and($demoRequest->institute_name)->toBe('Bright Future Academy')
        ->and($demoRequest->city)->toBe('Pune')
        ->and($demoRequest->status)->toBe(DemoRequestStatus::New);
});

test('the success message is shown on the same page after submitting', function () {
    $response = $this->post('http://coaching.test/demo-requests', validDemoRequestPayload());

    $response->assertRedirect('http://coaching.test');

    $this->get($response->headers->get('Location'))
        ->assertSee(__('platform.home.demo_form.success'));
});

test('validation messages are in plain language', function () {
    $response = $this->post('http://coaching.test/demo-requests', validDemoRequestPayload(['name' => '', 'phone' => '', 'institute_name' => '', 'city' => '']));

    $response->assertSessionHasErrors([
        'name' => __('platform.home.demo_form.errors.name_required'),
        'phone' => __('platform.home.demo_form.errors.phone_required'),
        'institute_name' => __('platform.home.demo_form.errors.institute_name_required'),
        'city' => __('platform.home.demo_form.errors.city_required'),
    ]);

    expect(DemoRequest::query()->count())->toBe(0);
});

test('a filled honeypot field silently rejects the submission — no error, nothing stored', function () {
    $response = $this->post('http://coaching.test/demo-requests', validDemoRequestPayload(['website' => 'http://spam.example.com']));

    // Looks like success to the bot.
    $response->assertRedirect('http://coaching.test');
    $response->assertSessionDoesntHaveErrors();

    expect(DemoRequest::query()->count())->toBe(0);
});

test('the demo request form is rate limited to 5 per hour per IP', function () {
    // Positive control: 5 submissions from the same IP all succeed first.
    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1'])
            ->post('http://coaching.test/demo-requests', validDemoRequestPayload(['email' => "person{$i}@example.com"]))
            ->assertRedirect();
    }

    expect(DemoRequest::query()->count())->toBe(5);

    // The 6th from the same IP is blocked.
    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1'])
        ->post('http://coaching.test/demo-requests', validDemoRequestPayload(['email' => 'sixth@example.com']));

    $response->assertStatus(429);
    expect(DemoRequest::query()->count())->toBe(5);

    // A different IP is not affected.
    $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.2'])
        ->post('http://coaching.test/demo-requests', validDemoRequestPayload(['email' => 'different-ip@example.com']))
        ->assertRedirect();

    expect(DemoRequest::query()->count())->toBe(6);
});

test('the demo request route is 404 on a tenant domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

    // Positive control: the same request succeeds on the central domain.
    $this->post('http://coaching.test/demo-requests', validDemoRequestPayload())->assertRedirect();

    $response = $this->post("http://{$domain}/demo-requests", validDemoRequestPayload(['email' => 'tenant-attempt@example.com']));

    $response->assertNotFound();
});
