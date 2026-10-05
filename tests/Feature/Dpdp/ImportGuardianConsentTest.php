<?php

use App\Enums\ConsentMethod;
use App\Models\Consent;
use App\Models\Tenant;
use App\Models\User;

/**
 * Phase 16 §1 — import consent. The owner must tick "I hold guardian consent for these
 * students" on the preview; confirm without it creates nothing; each consent is recorded
 * with the new owner_attested method (never guardian_in_person); owners see students who
 * have no guardian details on their dashboard.
 */
function p16ImportFixture(): array
{
    $tenant = Tenant::factory()->create();
    $domain = 'tenant-a.coaching.test';
    $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);
    $owner = inTenant($tenant, fn () => User::factory()->owner()->create());

    return [$tenant, $domain, $owner];
}

function p16Preview(string $domain, User $owner, string $csv): string
{
    $response = test()->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile($csv)]);
    $response->assertOk();

    return $response->viewData('token');
}

test('the import preview shows the guardian-consent checkbox', function () {
    [, $domain, $owner] = p16ImportFixture();

    // Positive control: the preview itself renders for the owner.
    $preview = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import", ['file' => csvUploadFile("name,phone,email\nAsha Rao,9876543210,\n")]);
    $preview->assertOk();

    $preview->assertSee(__('consents.import.guardian_consent_label'), false);
    $preview->assertSee('name="guardian_consent"', false);
});

test('confirm without the guardian-consent checkbox creates nothing and asks for it (positive control: with it, students are created)', function () {
    [$tenant, $domain, $owner] = p16ImportFixture();
    $csv = "name,phone,email\nAsha Rao,9876543210,\n";

    // Positive control: with the checkbox ticked the same flow creates the student.
    $tokenWith = p16Preview($domain, $owner, $csv);
    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import/confirm", ['token' => $tokenWith, 'guardian_consent' => '1'])
        ->assertRedirect();
    inTenant($tenant, fn () => expect(User::where('phone', '9876543210')->exists())->toBeTrue());

    // Refusal: without it, a second, different student must NOT be created.
    $tokenWithout = p16Preview($domain, $owner, "name,phone,email\nRahul Jain,9123456780,\n");
    $response = $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import/confirm", ['token' => $tokenWithout]);

    $response->assertSessionHasErrors('guardian_consent');
    inTenant($tenant, fn () => expect(User::where('phone', '9123456780')->exists())->toBeFalse());
});

test('each consent from an import is recorded with method owner_attested, never guardian_in_person', function () {
    [$tenant, $domain, $owner] = p16ImportFixture();
    $token = p16Preview($domain, $owner, "name,phone,email\nAsha Rao,9876543210,\n");

    $this->actingAs($owner, 'tenant')
        ->post("http://{$domain}/users/import/confirm", ['token' => $token, 'guardian_consent' => '1'])
        ->assertRedirect();

    // The new enum case must exist — a missing case fails here, not silently.
    expect(ConsentMethod::tryFrom('owner_attested'))->not->toBeNull();

    inTenant($tenant, function () {
        $student = User::where('phone', '9876543210')->firstOrFail();
        $consents = Consent::where('user_id', $student->id)->get();

        // Positive control: consents were actually written for every purpose.
        expect($consents)->not->toBeEmpty();
        expect($consents->pluck('method')->unique()->all())->toBe(['owner_attested']);
    });
});

test('the owner dashboard lists students with no guardian details, and not those who have them (positive control)', function () {
    [$tenant, $domain, $owner] = p16ImportFixture();
    [$missing, $complete] = inTenant($tenant, function () {
        $missing = User::factory()->student()->create(['name' => 'No Guardian Yet']);
        $complete = User::factory()->student()->create(['name' => 'Has Guardian']);
        $complete->forceFill([
            'guardian_name' => 'Parent Name',
            'guardian_relationship' => 'father',
            'guardian_phone' => '9000000001',
        ])->save();

        return [$missing, $complete];
    });

    $response = $this->actingAs($owner, 'tenant')->get("http://{$domain}/dashboard");
    $response->assertOk();

    // Bare key-presence first, so a missing view variable can't pass a closure check.
    $response->assertViewHas('studentsMissingGuardian');
    $response->assertViewHas('studentsMissingGuardian', function ($students) use ($missing, $complete) {
        $ids = collect($students)->pluck('id')->all();

        return in_array($missing->id, $ids, true) && ! in_array($complete->id, $ids, true);
    });
    $response->assertSee('No Guardian Yet');
});

test('students never see the owner guardian-gap list', function () {
    [$tenant, $domain, $owner] = p16ImportFixture();
    $student = inTenant($tenant, fn () => User::factory()->student()->create());

    // Positive control: the owner sees the list at all.
    $this->actingAs($owner, 'tenant')->get("http://{$domain}/dashboard")->assertViewHas('studentsMissingGuardian');

    freshRequestCycle();

    $this->actingAs($student, 'tenant')
        ->get("http://{$domain}/dashboard")
        ->assertDontSee(__('consents.guardian_gap.title'));
});
