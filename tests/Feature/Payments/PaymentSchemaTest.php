<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PaymentFixtures;

// === Columns and indexes ===

test('the payments table carries every column the manual UPI flow writes', function () {
    expect(Schema::hasTable('payments'))->toBeTrue();

    expect(collect([
        'id',
        'tenant_id',
        'enrolment_id',
        'amount_paise',
        'upi_reference',
        'screenshot_path',
        'status',
        'rejection_reason',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'created_at',
        'updated_at',
    ])->every(fn (string $column) => Schema::hasColumn('payments', $column)))->toBeTrue();
});

test('payments is unique on (tenant_id, id) and indexed on (tenant_id, status, submitted_at)', function () {
    $indexes = Schema::getIndexes('payments');

    expect(collect($indexes)->contains(
        fn (array $index) => $index['unique'] === true && $index['columns'] === ['tenant_id', 'id']
    ))->toBeTrue();

    expect(collect($indexes)->contains(
        fn (array $index) => $index['unique'] === false && $index['columns'] === ['tenant_id', 'status', 'submitted_at']
    ))->toBeTrue();
});

test('the columns this phase adds to tenants, courses and enrolments all exist', function () {
    expect(Schema::hasColumn('tenants', 'upi_id'))->toBeTrue();
    expect(Schema::hasColumn('courses', 'fee_paise'))->toBeTrue();
    expect(Schema::hasColumn('enrolments', 'approved_payment_id'))->toBeTrue();
});

// === Composite FK: payment -> enrolment ===

test('a payment cannot reference an enrolment from another tenant', function () {
    $tenantA = PaymentFixtures::tenant('tenant-a.coaching.test');
    $tenantB = PaymentFixtures::tenant('tenant-b.coaching.test');

    $enrolmentA = PaymentFixtures::people($tenantA)['enrolment'];
    $peopleB = PaymentFixtures::people($tenantB);

    // Positive control: a payment pointing at its own tenant's enrolment is accepted
    expect(PaymentFixtures::payment($tenantB, $peopleB['enrolment'])->id)->not->toBeNull();

    // Negative: enrolment_id is tenant A's row while the payment is written as tenant B
    expect(fn () => PaymentFixtures::payment($tenantB, $enrolmentA))
        ->toThrow(QueryException::class);
});

// === Composite FK: payment -> reviewing user ===

test("a payment's reviewed_by cannot reference a user from another tenant", function () {
    $tenantA = PaymentFixtures::tenant('tenant-a.coaching.test');
    $tenantB = PaymentFixtures::tenant('tenant-b.coaching.test');

    $ownerA = PaymentFixtures::people($tenantA)['owner'];
    $peopleB = PaymentFixtures::people($tenantB);

    $approved = ['status' => PaymentStatus::Approved, 'reviewed_at' => now()];

    // Positive control: a payment reviewed by a user in its own tenant is accepted
    expect(PaymentFixtures::payment(
        $tenantB,
        $peopleB['enrolment'],
        $approved + ['reviewed_by' => $peopleB['owner']->id],
    )->id)->not->toBeNull();

    // Negative: reviewed_by is tenant A's owner while the payment belongs to tenant B
    expect(fn () => PaymentFixtures::payment(
        $tenantB,
        $peopleB['enrolment'],
        $approved + ['reviewed_by' => $ownerA->id],
    ))->toThrow(QueryException::class);
});

// === Composite FK: enrolment -> approved payment ===

test("an enrolment's approved_payment_id cannot reference a payment from another tenant", function () {
    $tenantA = PaymentFixtures::tenant('tenant-a.coaching.test');
    $tenantB = PaymentFixtures::tenant('tenant-b.coaching.test');

    $peopleA = PaymentFixtures::people($tenantA);
    $peopleB = PaymentFixtures::people($tenantB);

    $paymentA = PaymentFixtures::payment($tenantA, $peopleA['enrolment']);
    $paymentB = PaymentFixtures::payment($tenantB, $peopleB['enrolment']);

    // Positive control: pointing an enrolment at its own tenant's payment is accepted
    inTenant($tenantB, function () use ($peopleB, $paymentB) {
        $peopleB['enrolment']->forceFill(['approved_payment_id' => $paymentB->id])->save();

        expect((int) $peopleB['enrolment']->refresh()->approved_payment_id)->toBe($paymentB->id);
    });

    // Negative: approved_payment_id is tenant A's payment on tenant B's enrolment
    expect(fn () => inTenant($tenantB, function () use ($peopleB, $paymentA) {
        $peopleB['enrolment']->forceFill(['approved_payment_id' => $paymentA->id])->save();
    }))->toThrow(QueryException::class);
});

// === Mass assignment ===

test('amount_paise, status, submitted_at, reviewed_at, reviewed_by, rejection_reason, enrolment_id and tenant_id are not mass-assignable from request input', function () {
    Storage::fake('local');
    $f = PaymentFixtures::setup(['fee_paise' => PaymentFixtures::FEE_PAISE]);

    // Positive control: the two columns that ARE permitted from the request take effect
    $this->actingAs($f['student'], 'tenant')
        ->post("http://{$f['domain']}/enrolments/{$f['enrolment']->id}/payment", [
            'screenshot' => PaymentFixtures::image(640, 480),
            'upi_reference' => 'ISSUE-42',
            // Every guarded column, posted alongside them, aiming somewhere else entirely:
            'amount_paise' => 1,
            'status' => 'approved',
            'submitted_at' => '2020-01-01 00:00:00',
            'reviewed_at' => '2020-01-01 00:00:00',
            'reviewed_by' => 999999,
            'rejection_reason' => 'written by the client',
            'enrolment_id' => 999999,
            'tenant_id' => 999999,
            'screenshot_path' => 'tenants/999/payments/evil.png',
        ])
        ->assertRedirect();

    $payment = inTenant($f['tenant'], fn () => Payment::firstOrFail());

    expect($payment->upi_reference)->toBe('ISSUE-42');
    expect($payment->screenshot_path)->not->toBe('tenants/999/payments/evil.png');
    expect($payment->screenshot_path)->toStartWith("tenants/{$f['tenant']->id}/payments/");

    // Negative: everything guarded stayed server-derived
    expect((int) $payment->amount_paise)->toBe(PaymentFixtures::FEE_PAISE)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->reviewed_at)->toBeNull()
        ->and($payment->reviewed_by)->toBeNull()
        ->and($payment->rejection_reason)->toBeNull()
        ->and((int) $payment->enrolment_id)->toBe($f['enrolment']->id)
        ->and((int) $payment->tenant_id)->toBe($f['tenant']->id);

    // submitted_at must be "now", never the 2020-01-01 the client asked for
    expect($payment->submitted_at->isAfter('2021-01-01'))->toBeTrue();
});
