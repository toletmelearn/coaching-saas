<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ConsentFixtures;

// === Recording consent against an existing student ===

test('the owner can record a consent for each purpose, stamped with who recorded it, how and which notice version', function () {
    $f = ConsentFixtures::setup();

    foreach (ConsentFixtures::PURPOSES as $purpose) {
        $this->actingAs($f['owner'], 'tenant')
            ->post(ConsentFixtures::consentsUrl($f['domain'], $f['student']), ConsentFixtures::recordPayload($purpose))
            ->assertRedirect();
    }

    $rows = DB::table('consents')->where('user_id', $f['student']->id)->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('purpose')->sort()->values()->all())
        ->toBe(collect(ConsentFixtures::PURPOSES)->sort()->values()->all());

    foreach ($rows as $row) {
        expect((int) $row->recorded_by)->toBe($f['owner']->id)
            ->and((int) $row->tenant_id)->toBe($f['tenant']->id)
            ->and($row->method)->toBe('guardian_in_person')
            ->and($row->notice_version)->toBe(ConsentFixtures::NOTICE_VERSION)
            ->and($row->granted_at)->not->toBeNull()
            ->and($row->withdrawn_at)->toBeNull();
    }
});

test('staff can record a consent exactly like the owner', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['staff'], 'tenant')
        ->post(ConsentFixtures::consentsUrl($f['domain'], $f['student']), ConsentFixtures::recordPayload('course_delivery'))
        ->assertRedirect();

    $row = DB::table('consents')->where('user_id', $f['student']->id)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->recorded_by)->toBe($f['staff']->id)
        ->and($row->purpose)->toBe('course_delivery');
});

test('a student cannot record a consent, not even their own', function () {
    $f = ConsentFixtures::setup();

    // Positive control: the owner's identical request succeeds, so the 403 below
    // is an authorization refusal, not a missing route.
    $this->actingAs($f['owner'], 'tenant')
        ->post(ConsentFixtures::consentsUrl($f['domain'], $f['student']), ConsentFixtures::recordPayload('course_delivery'))
        ->assertRedirect();

    $this->actingAs($f['student'], 'tenant')
        ->post(ConsentFixtures::consentsUrl($f['domain'], $f['student']), ConsentFixtures::recordPayload('communication'))
        ->assertForbidden();

    expect(DB::table('consents')->where('user_id', $f['student']->id)->count())->toBe(1);
});

test('an unknown purpose is rejected', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->post(ConsentFixtures::consentsUrl($f['domain'], $f['student']), ConsentFixtures::recordPayload('mind_control'))
        ->assertSessionHasErrors('purpose');

    expect(DB::table('consents')->where('user_id', $f['student']->id)->count())->toBe(0);
});

test('an unknown collection method is rejected', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->post(ConsentFixtures::consentsUrl($f['domain'], $f['student']), ConsentFixtures::recordPayload('course_delivery', [
            'consent_method' => 'hologram',
        ]))
        ->assertSessionHasErrors('consent_method');

    expect(DB::table('consents')->where('user_id', $f['student']->id)->count())->toBe(0);
});

test('guardian details posted with a consent are saved onto the student', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->post(ConsentFixtures::consentsUrl($f['domain'], $f['student']), ConsentFixtures::recordPayload(
            'course_delivery',
            ConsentFixtures::guardianFields(),
        ))
        ->assertRedirect();

    $student = inTenant($f['tenant'], fn () => User::find($f['student']->id));

    expect($student->guardian_name)->toBe('Meera Rao')
        ->and($student->guardian_relationship)->toBe('Mother')
        ->and($student->guardian_phone)->toBe('9876500001')
        ->and($student->guardian_email)->toBe('meera@example.com');
});

test('the same purpose can be recorded again at a later time as a second consent', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->post(ConsentFixtures::consentsUrl($f['domain'], $f['student']), ConsentFixtures::recordPayload('course_delivery'))
        ->assertRedirect();

    $this->travel(1)->minute();

    $this->actingAs($f['owner'], 'tenant')
        ->post(ConsentFixtures::consentsUrl($f['domain'], $f['student']), ConsentFixtures::recordPayload('course_delivery'))
        ->assertRedirect();

    $rows = DB::table('consents')
        ->where('user_id', $f['student']->id)
        ->where('purpose', 'course_delivery')
        ->orderBy('granted_at')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->granted_at)->not->toBe($rows[1]->granted_at);

    foreach ($rows as $row) {
        expect($row->withdrawn_at)->toBeNull();
    }
});

// === Consent recorded as part of student creation ===

test('creating a student records the guardian details and a consent for every purpose in one request', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users", ConsentFixtures::studentCreationPayload())
        ->assertRedirect();

    $student = inTenant($f['tenant'], fn () => User::where('email', 'dpdp-student@example.com')->first());

    expect($student)->not->toBeNull()
        ->and($student->guardian_name)->toBe('Meera Rao')
        ->and($student->guardian_relationship)->toBe('Mother')
        ->and($student->guardian_phone)->toBe('9876500001');

    $rows = DB::table('consents')->where('user_id', $student->id)->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('purpose')->sort()->values()->all())
        ->toBe(collect(ConsentFixtures::PURPOSES)->sort()->values()->all());

    foreach ($rows as $row) {
        expect((int) $row->recorded_by)->toBe($f['owner']->id)
            ->and($row->method)->toBe('guardian_in_person')
            ->and($row->notice_version)->toBe(ConsentFixtures::NOTICE_VERSION)
            ->and($row->granted_at)->not->toBeNull();
    }
});

test('a student is not created until consent is recorded for every purpose', function () {
    $f = ConsentFixtures::setup();

    $payload = ConsentFixtures::studentCreationPayload();
    $payload['consents'] = ['course_delivery', 'progress_tracking', 'communication'];

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users", $payload)
        ->assertSessionHasErrors('consents', __('consents.errors.purposes_required'));

    expect(inTenant($f['tenant'], fn () => User::where('email', 'dpdp-student@example.com')->count()))->toBe(0)
        ->and(DB::table('consents')->count())->toBe(0);
});

test('a student is not created until the guardian details are recorded', function () {
    $f = ConsentFixtures::setup();

    $payload = ConsentFixtures::studentCreationPayload();
    unset($payload['guardian_name']);

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users", $payload)
        ->assertSessionHasErrors('guardian_name');

    expect(inTenant($f['tenant'], fn () => User::where('email', 'dpdp-student@example.com')->count()))->toBe(0)
        ->and(DB::table('consents')->count())->toBe(0);
});

test('a consent for an unknown purpose blocks student creation', function () {
    $f = ConsentFixtures::setup();

    $payload = ConsentFixtures::studentCreationPayload();
    $payload['consents'] = ['course_delivery', 'mind_control', 'communication', 'media_processing'];

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users", $payload)
        ->assertSessionHasErrors('consents.1');

    expect(inTenant($f['tenant'], fn () => User::where('email', 'dpdp-student@example.com')->count()))->toBe(0)
        ->and(DB::table('consents')->count())->toBe(0);
});

test('a consent for an unknown collection method blocks student creation', function () {
    $f = ConsentFixtures::setup();

    $payload = ConsentFixtures::studentCreationPayload(['consent_method' => 'hologram']);

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users", $payload)
        ->assertSessionHasErrors('consent_method');

    expect(inTenant($f['tenant'], fn () => User::where('email', 'dpdp-student@example.com')->count()))->toBe(0)
        ->and(DB::table('consents')->count())->toBe(0);
});

test('staff can create a student with guardian details and consent', function () {
    $f = ConsentFixtures::setup();

    $payload = ConsentFixtures::studentCreationPayload([
        'name' => 'Staff Created Student',
        'email' => 'staff-created@example.com',
        'phone' => '9876500002',
    ]);

    $this->actingAs($f['staff'], 'tenant')
        ->post("http://{$f['domain']}/users", $payload)
        ->assertRedirect();

    $student = inTenant($f['tenant'], fn () => User::where('email', 'staff-created@example.com')->first());

    expect($student)->not->toBeNull()
        ->and($student->guardian_name)->toBe('Meera Rao');

    $rows = DB::table('consents')->where('user_id', $student->id)->get();

    expect($rows)->toHaveCount(4);

    foreach ($rows as $row) {
        expect((int) $row->recorded_by)->toBe($f['staff']->id);
    }
});

test('guardian details posted for a non-student are not stored', function () {
    $f = ConsentFixtures::setup();

    // Guardian columns are Step 2 schema — without them the discarded-value
    // assertion below would pass vacuously, so prove they exist first.
    expect(Schema::hasColumns('users', [
        'guardian_name',
        'guardian_phone',
        'guardian_email',
        'guardian_relationship',
    ]))->toBeTrue();

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users", [
            'name' => 'Second Owner',
            'email' => 'second-owner@example.com',
            'role' => 'owner',
        ] + ConsentFixtures::guardianFields())
        ->assertRedirect();

    $owner = inTenant($f['tenant'], fn () => User::where('email', 'second-owner@example.com')->first());

    expect($owner)->not->toBeNull()
        ->and($owner->role->value)->toBe('owner');

    foreach (['guardian_name', 'guardian_phone', 'guardian_email', 'guardian_relationship'] as $column) {
        expect($owner->getAttribute($column))->toBeNull();
    }
});

// === Bulk import ===

test('bulk import shows the consent notice and records a consent for every imported student', function () {
    $f = ConsentFixtures::setup();

    $csv = "name,phone,email\n"
        ."Import One,9876540001,\n"
        ."Import Two,,import-two@example.com\n";

    $preview = $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users/import", ['file' => csvUploadFile($csv)]);

    $preview->assertOk();

    expect(__('consents.notice_title'))->not->toBe('consents.notice_title');
    $preview->assertSee(__('consents.notice_title'));

    $this->actingAs($f['owner'], 'tenant')
        ->post("http://{$f['domain']}/users/import/confirm", ['token' => $preview->viewData('token')])
        ->assertRedirect();

    $imported = [
        inTenant($f['tenant'], fn () => User::where('phone', '9876540001')->first()),
        inTenant($f['tenant'], fn () => User::where('email', 'import-two@example.com')->first()),
    ];

    foreach ($imported as $student) {
        expect($student)->not->toBeNull();

        $rows = DB::table('consents')->where('user_id', $student->id)->get();

        expect($rows)->toHaveCount(4)
            ->and($rows->pluck('purpose')->sort()->values()->all())
            ->toBe(collect(ConsentFixtures::PURPOSES)->sort()->values()->all());

        foreach ($rows as $row) {
            expect($row->method)->toBe(ConsentFixtures::IMPORT_METHOD)
                ->and((int) $row->recorded_by)->toBe($f['owner']->id)
                ->and($row->notice_version)->toBe(ConsentFixtures::NOTICE_VERSION);
        }
    }
});
