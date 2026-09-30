<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\DatabaseVersionCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AppPreflightCommand extends Command
{
    protected $signature = 'app:preflight';

    protected $description = 'Verify the production environment is safely configured before/after a deploy.';

    public function handle(): int
    {
        if (! app()->environment('production')) {
            $this->components->info('Not running in production — preflight checks are informational only here.');

            return self::SUCCESS;
        }

        $failures = array_filter([
            $this->checkDebugDisabled(),
            $this->checkAppKey(),
            $this->checkAppUrlIsHttps(),
            $this->checkDomainsConfigured(),
            $this->checkSessionSecureCookie(),
            ...$this->checkWritablePaths(),
            $this->checkDatabaseReachable(),
            $this->checkMysqlVersion(),
            $this->checkNoDemoData(),
            $this->checkNoLocalhostDemoDomain(),
            $this->checkVideoDriver(),
            $this->checkGdFreetype(),
        ]);

        if ($failures === []) {
            $this->components->info('All preflight checks passed.');

            return self::SUCCESS;
        }

        $this->components->error(sprintf('%d preflight check(s) failed:', count($failures)));

        foreach ($failures as $failure) {
            $this->components->twoColumnDetail($failure, '<fg=red>FAIL</>');
        }

        return self::FAILURE;
    }

    private function checkDebugDisabled(): ?string
    {
        return config('app.debug') === true
            ? 'APP_DEBUG is true — must be false in production.'
            : null;
    }

    private function checkAppKey(): ?string
    {
        return empty(config('app.key'))
            ? 'APP_KEY is empty — run php artisan key:generate.'
            : null;
    }

    private function checkAppUrlIsHttps(): ?string
    {
        $url = (string) config('app.url');

        return str_starts_with($url, 'https://')
            ? null
            : "APP_URL must start with https:// (got: \"{$url}\").";
    }

    private function checkDomainsConfigured(): ?string
    {
        $platformDomain = config('tenancy.platform_domain');
        $baseDomain = config('tenancy.tenant_base_domain');
        $central = config('tenancy.central_domains', []);

        if (in_array($platformDomain, [null, '', 'coaching.test'], true)
            || in_array($baseDomain, [null, '', 'coaching.test'], true)
            || $central === []) {
            return 'PLATFORM_DOMAIN / TENANT_BASE_DOMAIN / CENTRAL_DOMAINS are not configured for production (still at the local dev default).';
        }

        return null;
    }

    private function checkSessionSecureCookie(): ?string
    {
        return config('session.secure') === true
            ? null
            : 'SESSION_SECURE_COOKIE is not true — session cookies would be sent over plain HTTP.';
    }

    /**
     * @return array<int, ?string>
     */
    private function checkWritablePaths(): array
    {
        return array_map(
            fn (string $path) => is_writable($path) ? null : "Not writable: {$path}",
            config('preflight.writable_paths', []),
        );
    }

    private function checkDatabaseReachable(): ?string
    {
        try {
            DB::connection()->getPdo();

            return null;
        } catch (Throwable $e) {
            return 'Database is unreachable: '.$e->getMessage();
        }
    }

    private function checkMysqlVersion(): ?string
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            // Not a MySQL/MariaDB connection (e.g. SQLite in local/testing) — this check
            // only makes sense against the production database engine.
            return null;
        }

        try {
            $version = DB::selectOne('SELECT VERSION() as version')->version;
        } catch (Throwable) {
            // Already reported by checkDatabaseReachable(); don't double-report.
            return null;
        }

        return DatabaseVersionCheck::minimumViolationMessage($version);
    }

    private function checkNoDemoData(): ?string
    {
        $baseDomain = config('tenancy.tenant_base_domain');

        try {
            $demoExists = Tenant::query()->where('name', 'Demo Institute')->exists()
                || TenantDomain::query()->where('domain', "demo.{$baseDomain}")->exists()
                || PlatformAdmin::query()->where('email', 'admin@coaching.test')->exists();
        } catch (Throwable) {
            // Already reported by checkDatabaseReachable(); don't double-report.
            return null;
        }

        return $demoExists
            ? 'A demo tenant/account exists — never seed demo data in production (see README.md "Local credentials" and docs/DEPLOY.md).'
            : null;
    }

    /**
     * The local-only demo.localhost domain (registered by TenantSeeder so the PWA can be
     * tested over a secure context without HTTPS — see README.md "Local credentials")
     * must never exist in production.
     */
    private function checkNoLocalhostDemoDomain(): ?string
    {
        try {
            $exists = TenantDomain::query()->where('domain', 'demo.localhost')->exists();
        } catch (Throwable) {
            return null;
        }

        return $exists
            ? 'The demo.localhost domain exists — this is a local PWA-testing-only domain and must never exist in production.'
            : null;
    }

    /**
     * The default institute icon (Phase 6) is drawn with GD's imagettftext(), which
     * needs FreeType support compiled into GD. Read from config (see
     * config/preflight.php) rather than calling extension_loaded('gd')/gd_info()
     * directly, so this is mockable in tests without needing a PHP build that's
     * actually missing the extension.
     */
    private function checkGdFreetype(): ?string
    {
        if (! config('preflight.gd_extension_loaded')) {
            return 'The GD PHP extension is not loaded — required to generate institute icons and re-encode uploaded logos.';
        }

        if (! config('preflight.gd_freetype_supported')) {
            return "GD is loaded but was built without FreeType support — required to draw the default institute icon's letter.";
        }

        return null;
    }

    /**
     * Only the account-level Bunny key is checked here — per-tenant library keys don't
     * exist yet for a tenant that has never uploaded video, so those are validated lazily
     * at first use (docs/specs/phase-5-video.md).
     */
    private function checkVideoDriver(): ?string
    {
        $driver = config('coaching.video_driver');

        if ($driver === 'fake') {
            return 'VIDEO_DRIVER=fake is never allowed in production.';
        }

        if ($driver === 'bunny' && empty(config('services.bunny.account_api_key'))) {
            return 'VIDEO_DRIVER=bunny but BUNNY_STREAM_ACCOUNT_API_KEY is not set.';
        }

        return null;
    }
}
