<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ConsentFixtures;

// === Schema: the consents table ===

test('the consents table exists with every column the contract names', function () {
    expect(Schema::hasTable('consents'))->toBeTrue();

    expect(Schema::hasColumns('consents', [
        'tenant_id',
        'user_id',
        'guardian_id',
        'purpose',
        'notice_version',
        'method',
        'granted_at',
        'withdrawn_at',
        'withdrawn_reason',
        'recorded_by',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('a consent always carries a grant time, a purpose, a method and a recorder, while withdrawal columns start empty', function () {
    expect(Schema::hasTable('consents'))->toBeTrue();

    $columns = collect(Schema::getColumns('consents'))->keyBy('name');

    foreach (['granted_at', 'purpose', 'method', 'recorded_by', 'withdrawn_at', 'withdrawn_reason'] as $column) {
        expect($columns->has($column))->toBeTrue();
    }

    expect($columns['granted_at']['nullable'])->toBeFalse()
        ->and($columns['purpose']['nullable'])->toBeFalse()
        ->and($columns['method']['nullable'])->toBeFalse()
        ->and($columns['recorded_by']['nullable'])->toBeFalse()
        ->and($columns['withdrawn_at']['nullable'])->toBeTrue()
        ->and($columns['withdrawn_reason']['nullable'])->toBeTrue();
});

// === Schema: foreign keys ===

test('the consents foreign keys are composite: a row may only name a student, recorder or guardian in its own tenant', function () {
    $f = ConsentFixtures::setup();

    $tenantB = Tenant::factory()->create();
    $tenantB->domains()->create(['domain' => 'tenant-b.coaching.test', 'type' => 'subdomain']);
    $studentB = inTenant($tenantB, fn () => User::factory()->student()->create());

    $row = fn (array $overrides = []) => $overrides + [
        'tenant_id' => $f['tenant']->id,
        'user_id' => $f['student']->id,
        'guardian_id' => null,
        'purpose' => 'course_delivery',
        'notice_version' => ConsentFixtures::NOTICE_VERSION,
        'method' => 'guardian_in_person',
        'granted_at' => '2026-01-01 10:00:00',
        'recorded_by' => $f['owner']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // Positive control: a fully consistent row inserts. This proves the failures
    // below come from the mismatched columns, not from a missing table/column.
    expect(fn () => DB::table('consents')->insert($row()))
        ->not->toThrow(QueryException::class);

    // tenant_id → tenants.id
    expect(fn () => DB::table('consents')->insert($row([
        'tenant_id' => $f['tenant']->id + 1000000,
        'granted_at' => '2026-01-03 10:00:00',
    ])))->toThrow(QueryException::class);

    // (tenant_id, user_id) → users(tenant_id, id): tenant A's tenant with tenant B's student.
    expect(fn () => DB::table('consents')->insert($row([
        'user_id' => $studentB->id,
        'granted_at' => '2026-01-04 10:00:00',
    ])))->toThrow(QueryException::class);

    // (tenant_id, recorded_by) → users(tenant_id, id): tenant A's tenant with tenant B's recorder.
    expect(fn () => DB::table('consents')->insert($row([
        'recorded_by' => $studentB->id,
        'granted_at' => '2026-01-05 10:00:00',
    ])))->toThrow(QueryException::class);

    // (tenant_id, guardian_id) → users(tenant_id, id): guardian is nullable but must
    // still be same-tenant when present.
    expect(fn () => DB::table('consents')->insert($row([
        'guardian_id' => $studentB->id,
        'granted_at' => '2026-01-06 10:00:00',
    ])))->toThrow(QueryException::class);

    // None of the mismatched rows landed.
    expect(DB::table('consents')->where('tenant_id', $f['tenant']->id)->count())->toBe(1);
});

test('consents are unique per (tenant, student, purpose, grant time) so the same purpose can be granted again later', function () {
    $f = ConsentFixtures::setup();

    $row = fn (string $grantedAt) => [
        'tenant_id' => $f['tenant']->id,
        'user_id' => $f['student']->id,
        'guardian_id' => null,
        'purpose' => 'course_delivery',
        'notice_version' => ConsentFixtures::NOTICE_VERSION,
        'method' => 'guardian_in_person',
        'granted_at' => $grantedAt,
        'recorded_by' => $f['owner']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // A first grant, then a second grant of the same purpose on a later day.
    DB::table('consents')->insert($row('2026-01-01 10:00:00'));
    DB::table('consents')->insert($row('2026-01-02 10:00:00'));

    expect(DB::table('consents')->where('tenant_id', $f['tenant']->id)->count())->toBe(2);

    // The same purpose at the exact same instant is a duplicate, not a second consent.
    expect(fn () => DB::table('consents')->insert($row('2026-01-02 10:00:00')))
        ->toThrow(QueryException::class);

    expect(DB::table('consents')->where('tenant_id', $f['tenant']->id)->count())->toBe(2);
});

// === Schema: guardian columns on users ===

test('guardian fields exist on users, are nullable, and a student can exist without them', function () {
    $f = ConsentFixtures::setup();

    $guardianColumns = [
        'guardian_name',
        'guardian_phone',
        'guardian_email',
        'guardian_relationship',
    ];

    expect(Schema::hasColumns('users', $guardianColumns))->toBeTrue();

    $columns = collect(Schema::getColumns('users'))->keyBy('name');

    foreach ($guardianColumns as $column) {
        expect($columns->has($column))->toBeTrue()
            ->and($columns[$column]['nullable'])->toBeTrue()
            ->and($f['student']->getAttribute($column))->toBeNull();
    }
});

// === Mass assignment ===

test('guardian, tenancy, grant and withdrawal columns on consents are not mass assignable', function () {
    $f = ConsentFixtures::setup();
    $consent = ConsentFixtures::consentRow($f['tenant'], $f['student'], $f['owner'], 'course_delivery');

    // purpose is fillable — it is legitimate request data. If fill() below cannot
    // change even that, the guarded assertions would pass vacuously.
    // Wrapped in the tenant context like every other save in this suite: the
    // locked BelongsToTenant saving hook rejects any save without one, and the
    // point under test is fill()'s guarding, not context handling.
    inTenant($f['tenant'], function () use ($consent, $f) {
        $consent->fill([
            'tenant_id' => $f['tenant']->id + 1000000,
            'guardian_id' => $f['owner']->id,
            'recorded_by' => $f['staff']->id,
            'granted_at' => '2000-01-01 00:00:00',
            'withdrawn_at' => '2000-01-02 00:00:00',
            'withdrawn_reason' => 'forged',
            'notice_version' => 'forged-version',
            'purpose' => 'communication',
        ]);
        $consent->save();
    });

    $row = inTenant($f['tenant'], fn () => DB::table('consents')->where('id', $consent->id)->first());

    expect($row)->not->toBeNull()
        ->and((int) $row->tenant_id)->toBe($f['tenant']->id)
        ->and((int) $row->recorded_by)->toBe($f['owner']->id)
        ->and($row->guardian_id)->toBeNull()
        ->and($row->granted_at)->not->toBe('2000-01-01 00:00:00')
        ->and($row->withdrawn_at)->toBeNull()
        ->and($row->withdrawn_reason)->toBeNull()
        ->and($row->notice_version)->toBe(ConsentFixtures::NOTICE_VERSION)
        // The positive control: the fillable column did change.
        ->and($row->purpose)->toBe('communication');
});
