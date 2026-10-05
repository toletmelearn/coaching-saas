<?php

use App\Enums\UserRole;
use App\Models\User;
use Tests\Support\ConsentFixtures;

/**
 * Batch 1 (A): a user may edit their own name, but only an owner may change anyone's role. A
 * student or staff member PATCHing their own record with role=owner must be refused.
 */
function b1RoleUrl(string $domain, User $user): string
{
    return "http://{$domain}/users/{$user->id}";
}

test('a student PATCHing their own role to owner is refused and stays a student', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['student'], 'tenant')
        ->patch(b1RoleUrl($f['domain'], $f['student']), ['name' => 'Escalated', 'role' => 'owner']);

    $fresh = inTenant($f['tenant'], fn () => User::find($f['student']->id));
    expect($fresh->role)->toBe(UserRole::Student);
});

test('a staff member PATCHing their own role to owner is refused and stays staff', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['staff'], 'tenant')
        ->patch(b1RoleUrl($f['domain'], $f['staff']), ['role' => 'owner']);

    $fresh = inTenant($f['tenant'], fn () => User::find($f['staff']->id));
    expect($fresh->role)->toBe(UserRole::Staff);
});

test('an owner PATCHing their own role to staff is refused with 403, even while another owner exists', function () {
    $f = ConsentFixtures::setup();
    inTenant($f['tenant'], fn () => User::factory()->owner()->create());

    $this->actingAs($f['owner'], 'tenant')
        ->patch(b1RoleUrl($f['domain'], $f['owner']), ['role' => 'staff'])
        ->assertForbidden();

    $fresh = inTenant($f['tenant'], fn () => User::find($f['owner']->id));
    expect($fresh->role)->toBe(UserRole::Owner);
});

test('a self-edit that names the current role is also refused with 403', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['student'], 'tenant')
        ->patch(b1RoleUrl($f['domain'], $f['student']), ['role' => 'student'])
        ->assertForbidden();
});

test('staff changing another user\'s role is refused (already protected by UserPolicy::update)', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['staff'], 'tenant')
        ->patch(b1RoleUrl($f['domain'], $f['student']), ['role' => 'staff']);

    $fresh = inTenant($f['tenant'], fn () => User::find($f['student']->id));
    expect($fresh->role)->toBe(UserRole::Student);
});

test('positive control: a student may still change their own name', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['student'], 'tenant')
        ->patch(b1RoleUrl($f['domain'], $f['student']), ['name' => 'Renamed Student'])
        ->assertRedirect();

    $fresh = inTenant($f['tenant'], fn () => User::find($f['student']->id));
    expect($fresh->name)->toBe('Renamed Student');
});

test('positive control: an owner may still change a staff member\'s role', function () {
    $f = ConsentFixtures::setup();

    $this->actingAs($f['owner'], 'tenant')
        ->patch(b1RoleUrl($f['domain'], $f['staff']), ['role' => 'student'])
        ->assertRedirect();

    $fresh = inTenant($f['tenant'], fn () => User::find($f['staff']->id));
    expect($fresh->role)->toBe(UserRole::Student);
});
