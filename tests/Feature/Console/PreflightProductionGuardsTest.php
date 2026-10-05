<?php

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Artisan;

/**
 * In production, app:preflight fails when sessions are stored unencrypted, and fails when any
 * platform admin has no two-factor secret. Outside production these checks do not fail.
 */
function p16PreflightOutput(): array
{
    $originalEnv = app()['env'];
    app()['env'] = 'production';

    try {
        $code = Artisan::call('app:preflight', ['--require-production' => true]);

        return [$code, Artisan::output()];
    } finally {
        app()['env'] = $originalEnv;
    }
}

test('app:preflight fails in production when SESSION_ENCRYPT is false (positive control: with it true, the message is absent)', function () {
    config(['session.encrypt' => false]);
    [$code, $output] = p16PreflightOutput();

    expect($code)->not->toBe(0)
        ->and($output)->toContain('SESSION_ENCRYPT');

    config(['session.encrypt' => true]);
    [, $output] = p16PreflightOutput();

    expect($output)->not->toContain('SESSION_ENCRYPT');
});

test('app:preflight fails in production when a platform admin has no two-factor secret (positive control: with every admin enrolled, the message is absent)', function () {
    config(['session.encrypt' => true]);
    PlatformAdmin::factory()->create();

    [$code, $output] = p16PreflightOutput();
    expect($code)->not->toBe(0)
        ->and($output)->toContain('two-factor');

    PlatformAdmin::query()->get()->each(fn (PlatformAdmin $admin) => $admin->forceFill([
        'totp_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
        'totp_enabled_at' => now(),
    ])->save());

    [, $output] = p16PreflightOutput();
    expect($output)->not->toContain('two-factor');
});

test('.env.example turns session encryption on', function () {
    expect((string) file_get_contents(base_path('.env.example')))->toContain('SESSION_ENCRYPT=true');
});
