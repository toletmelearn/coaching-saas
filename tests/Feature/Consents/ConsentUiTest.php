<?php

use Tests\Support\ConsentFixtures;

// === Student creation: the consent notice ===

test('the student creation form shows the consent notice, the guardian fields and every purpose', function () {
    $f = ConsentFixtures::setup();

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/users/create");

    $response->assertOk();

    // The notice only exists once consents.php does — without it __() echoes the
    // key back and every assertSee below would be matching against nothing.
    expect(__('consents.notice_title'))->not->toBe('consents.notice_title');

    $response
        ->assertSee(__('consents.notice_title'))
        ->assertSee(__('consents.notice_body'))
        ->assertSee(__('consents.notice_version_label'))
        ->assertSee(ConsentFixtures::NOTICE_VERSION)
        ->assertSee(__('consents.guardian_heading'))
        ->assertSee(__('consents.fields.guardian_name'))
        ->assertSee(__('consents.fields.guardian_relationship'))
        ->assertSee(__('consents.fields.guardian_phone'))
        ->assertSee(__('consents.fields.method'));

    foreach (ConsentFixtures::PURPOSES as $purpose) {
        $response->assertSee(__('consents.purposes.'.$purpose))
            ->assertSee(__('consents.purpose_descriptions.'.$purpose));
    }

    foreach (['guardian_whatsapp', 'guardian_in_person', 'guardian_signed_form'] as $method) {
        $response->assertSee(__('consents.methods.'.$method));
    }
});

// === The consents screen ===

test('the consents screen lists every consent for the student with its grant or withdrawn status', function () {
    $f = ConsentFixtures::setup();

    ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'course_delivery');
    ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'communication', [
        'withdrawn_at' => now(),
        'withdrawn_reason' => 'Asked to stop the updates',
    ]);

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get(ConsentFixtures::consentsUrl($f['domain'], $f['student']));

    $response->assertOk();

    expect(__('consents.list_heading'))->not->toBe('consents.list_heading');

    $response
        ->assertSee(__('consents.list_heading'))
        ->assertSee(__('consents.record_heading'))
        ->assertSee($f['student']->name)
        ->assertSee(__('consents.purposes.course_delivery'))
        ->assertSee(__('consents.purposes.communication'))
        ->assertSee(__('consents.status_granted'))
        ->assertSee(__('consents.status_withdrawn'))
        ->assertSee(__('consents.recorded_by_label'))
        ->assertSee(__('consents.method_label'))
        ->assertSee(__('consents.withdrawn_reason_label'))
        ->assertSee('Asked to stop the updates');
});

// === Withdrawal ===

test('the withdrawal form renders for one consent and posts back to its withdraw endpoint', function () {
    $f = ConsentFixtures::setup();
    $consent = ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'course_delivery');

    $url = ConsentFixtures::withdrawUrl($f['domain'], $f['student'], $consent->id);

    $response = $this->actingAs($f['owner'], 'tenant')->get($url);

    $response->assertOk();

    expect(__('consents.withdraw_heading'))->not->toBe('consents.withdraw_heading');

    $response
        ->assertSee(__('consents.withdraw_heading'))
        ->assertSee(__('consents.purposes.course_delivery'))
        ->assertSee(__('consents.fields.reason'))
        ->assertSee(__('consents.withdraw_submit'))
        // The form must post back to the endpoint the tests exercise.
        ->assertSee("/manage/students/{$f['student']->id}/consents/{$consent->id}/withdraw", false);
});

// === Data export and erasure ===

test('the student data screen renders the export download and the erasure confirmation form', function () {
    $f = ConsentFixtures::setup();

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get(ConsentFixtures::dataUrl($f['domain'], $f['student']));

    $response->assertOk();

    expect(__('consents.data_heading'))->not->toBe('consents.data_heading');

    $response
        ->assertSee(__('consents.data_heading'))
        ->assertSee(__('consents.export_button'))
        ->assertSee(__('consents.erase_heading'))
        ->assertSee(__('consents.erase_warning'))
        ->assertSee(__('consents.fields.confirmation'))
        ->assertSee(__('consents.erase_submit'))
        // Links back to the endpoints the tests exercise.
        ->assertSee("/manage/students/{$f['student']->id}/data/export", false)
        ->assertSee("/manage/students/{$f['student']->id}/data", false);
});

// === Help ===

test('the Help page gains a plain-language section about student data', function () {
    $f = ConsentFixtures::setup();

    $response = $this->actingAs($f['owner'], 'tenant')
        ->get("http://{$f['domain']}/manage/help");

    $response->assertOk();

    expect(__('consents.help_title'))->not->toBe('consents.help_title');

    $response
        ->assertSee(__('consents.help_title'))
        ->assertSee(__('consents.help_body'));
});

// === Strings ===

test('every consent string resolves through lang/en/consents.php', function () {
    $keys = [
        'consents.notice_title',
        'consents.notice_body',
        'consents.notice_version_label',
        'consents.guardian_heading',
        'consents.fields.guardian_name',
        'consents.fields.guardian_relationship',
        'consents.fields.guardian_phone',
        'consents.fields.guardian_email',
        'consents.fields.method',
        'consents.fields.reason',
        'consents.fields.confirmation',
        'consents.methods.guardian_whatsapp',
        'consents.methods.guardian_in_person',
        'consents.methods.guardian_signed_form',
        'consents.purposes.course_delivery',
        'consents.purposes.progress_tracking',
        'consents.purposes.communication',
        'consents.purposes.media_processing',
        'consents.purpose_descriptions.course_delivery',
        'consents.purpose_descriptions.progress_tracking',
        'consents.purpose_descriptions.communication',
        'consents.purpose_descriptions.media_processing',
        'consents.list_heading',
        'consents.record_heading',
        'consents.status_granted',
        'consents.status_withdrawn',
        'consents.recorded_by_label',
        'consents.method_label',
        'consents.granted_at_label',
        'consents.withdrawn_at_label',
        'consents.withdrawn_reason_label',
        'consents.withdraw_heading',
        'consents.withdraw_submit',
        'consents.data_heading',
        'consents.export_button',
        'consents.erase_heading',
        'consents.erase_warning',
        'consents.erase_submit',
        'consents.help_title',
        'consents.help_body',
        'consents.errors.purposes_required',
        'consents.errors.confirmation_mismatch',
    ];

    foreach ($keys as $key) {
        expect(__($key))->not->toBe($key);
    }
});
