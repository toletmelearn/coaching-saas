<?php

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Hash;

test('creates a platform admin with a name, email, and confirmed password', function () {
    $this->artisan('platform-admin:create')
        ->expectsQuestion('Name', 'Alex Admin')
        ->expectsQuestion('Email', 'alex@example.com')
        ->expectsQuestion('Password (min 12 characters)', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'correct-horse-battery')
        ->assertSuccessful();

    $admin = PlatformAdmin::query()->where('email', 'alex@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->name)->toBe('Alex Admin')
        ->and(Hash::check('correct-horse-battery', $admin->password))->toBeTrue();
});

test('rejects a password shorter than 12 characters', function () {
    $this->artisan('platform-admin:create')
        ->expectsQuestion('Name', 'Short Pass')
        ->expectsQuestion('Email', 'shortpass@example.com')
        ->expectsQuestion('Password (min 12 characters)', 'short1234567') // 12 chars, control below is 11
        ->expectsQuestion('Confirm password', 'short1234567')
        ->assertSuccessful();

    expect(PlatformAdmin::query()->where('email', 'shortpass@example.com')->exists())->toBeTrue();

    $this->artisan('platform-admin:create')
        ->expectsQuestion('Name', 'Too Short')
        ->expectsQuestion('Email', 'tooshort@example.com')
        ->expectsQuestion('Password (min 12 characters)', 'short12345') // 10 chars
        ->assertFailed();

    expect(PlatformAdmin::query()->where('email', 'tooshort@example.com')->exists())->toBeFalse();
});

test('rejects mismatched password confirmation', function () {
    $this->artisan('platform-admin:create')
        ->expectsQuestion('Name', 'Mismatch')
        ->expectsQuestion('Email', 'mismatch@example.com')
        ->expectsQuestion('Password (min 12 characters)', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'different-password-here')
        ->assertFailed();

    expect(PlatformAdmin::query()->where('email', 'mismatch@example.com')->exists())->toBeFalse();
});

test('rejects an invalid email', function () {
    $this->artisan('platform-admin:create')
        ->expectsQuestion('Name', 'Bad Email')
        ->expectsQuestion('Email', 'not-an-email')
        ->assertFailed();

    expect(PlatformAdmin::query()->where('name', 'Bad Email')->exists())->toBeFalse();
});

test('refuses a duplicate email', function () {
    PlatformAdmin::factory()->create(['email' => 'existing@example.com']);

    $this->artisan('platform-admin:create')
        ->expectsQuestion('Name', 'Duplicate')
        ->expectsQuestion('Email', 'existing@example.com')
        ->assertFailed();

    expect(PlatformAdmin::query()->where('email', 'existing@example.com')->count())->toBe(1);
});
