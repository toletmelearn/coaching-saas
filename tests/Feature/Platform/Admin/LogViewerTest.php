<?php

use App\Models\PlatformAdmin;
use App\Support\Admin\LogTailer;

/**
 * Phase 13 feature D — /admin/logs: last-100 tail by default, ?q= searches the
 * last 5 MB. Read-only, backed by the injected LogTailer (temp path in tests).
 */
function p13LogContent(int $lines): string
{
    $out = [];

    for ($i = 1; $i <= $lines; $i++) {
        $out[] = sprintf('[2026-10-04] LOG-%04d some message', $i);
    }

    return implode("\n", $out)."\n";
}

test('the log page shows the last 100 lines of a long log', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'p13log');
    file_put_contents($tmp, p13LogContent(150));

    $this->app->bind(LogTailer::class, fn () => new LogTailer($tmp));

    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/logs');

    $response->assertOk();
    $response->assertSee('LOG-0150');
    $response->assertSee('LOG-0051');
    $response->assertDontSee('LOG-0050');
    $response->assertDontSee('LOG-0001');
    $response->assertSee(__('platform.admin.logs.count', ['count' => 100]));

    @unlink($tmp);
});

test('search returns only matching lines', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'p13log');
    file_put_contents($tmp, implode("\n", [
        '[2026-10-04] INFO everything fine',
        '[2026-10-04] ERROR database exploded',
        '[2026-10-04] INFO still fine',
        '[2026-10-04] ERROR cache also exploded',
    ])."\n");

    $this->app->bind(LogTailer::class, fn () => new LogTailer($tmp));

    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/logs?q=ERROR');

    $response->assertOk();
    $response->assertSee('database exploded');
    $response->assertSee('cache also exploded');
    $response->assertDontSee('everything fine');

    @unlink($tmp);
});

test('a query with no matches renders the empty state', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'p13log');
    file_put_contents($tmp, "[2026-10-04] INFO nothing to see\n");

    $this->app->bind(LogTailer::class, fn () => new LogTailer($tmp));

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/logs?q=UNICORN')
        ->assertOk()
        ->assertSee(__('platform.admin.logs.empty'));

    @unlink($tmp);
});

test('a missing log file renders the empty-state message, not an error', function () {
    $this->app->bind(LogTailer::class, fn () => new LogTailer(sys_get_temp_dir().'/p13-does-not-exist.log'));

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/logs')
        ->assertOk()
        ->assertSee(__('platform.admin.logs.no_file'));
});
