<?php

use App\Models\SystemSetting;

test('get returns the default when the key does not exist', function () {
    expect(SystemSetting::get('missing_key', 'fallback'))->toBe('fallback');
});

test('set stores a value and get retrieves it', function () {
    SystemSetting::set('max_tenants', 500);

    expect(SystemSetting::get('max_tenants'))->toBe(500);
});

test('set overwrites an existing value for the same key', function () {
    SystemSetting::set('feature_flag', ['enabled' => true]);
    SystemSetting::set('feature_flag', ['enabled' => false]);

    expect(SystemSetting::get('feature_flag'))->toBe(['enabled' => false])
        ->and(SystemSetting::query()->where('key', 'feature_flag')->count())->toBe(1);
});
