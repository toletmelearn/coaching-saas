<?php

use App\Enums\PlatformAdminStatus;
use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Hash;

test('a platform admin can be created and authenticated on its own guard', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin');

    expect(auth('platform_admin')->id())->toBe($admin->id)
        ->and($admin->status)->toBe(PlatformAdminStatus::Active);
});

test('platform admin password is hashed', function () {
    $admin = PlatformAdmin::factory()->create(['password' => 'plain-text-password']);

    expect($admin->password)->not->toBe('plain-text-password')
        ->and(Hash::check('plain-text-password', $admin->password))->toBeTrue();
});
