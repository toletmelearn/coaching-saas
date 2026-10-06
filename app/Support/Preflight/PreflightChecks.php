<?php

namespace App\Support\Preflight;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\Admin\ServiceSettings;
use App\Support\DatabaseVersionCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The check body of `php artisan app:preflight`, extracted in Phase 13 so the
 * /admin/health page runs exactly the same checks through the same code.
 *
 * Contract notes:
 *  - Failure *messages* and their order are a test contract
 *    (AppPreflightCommandTest, LiveClassPreflightTest, DriverSelectionTest all
 *    pin exact strings), so messages here are byte-identical to what the
 *    command printed before the extraction. Output formatting (components,
 *    colours, exit codes, the production gate) stays in AppPreflightCommand.
 *  - Credentials read through ServiceSettings (DB-first, config/env second) so
 *    keys an operator saved purely from /admin/settings/services pass deploy
 *    checks without .env ever being touched; with no DB row the value is
 *    exactly config(), i.e. identical behaviour to before Phase 13.
 *  - `fix` advertises an env-var repair the /admin/health Fix button may apply
 *    (APP_DEBUG-gated, see HealthController). Only checks whose fix is a pure
 *    env write are listed — never a value derived from a check failure.
 *
 * @phpstan-type Outcome array{id: string, label: string, passed: bool, message: ?string, fix: ?array{key: string, value: string}}
 */
class PreflightChecks
{
    /**
     * Run every check in the order `app:preflight` has always run them.
     *
     * @return list<Outcome>
     */
    public function run(): array
    {
        $outcomes = [];

        $add = function (?string $message, string $id, string $label, ?array $fix = null) use (&$outcomes): void {
            $outcomes[] = [
                'id' => $id,
                'label' => $label,
                'passed' => $message === null,
                'message' => $message,
                'fix' => $message === null ? null : $fix,
            ];
        };

        $add(
            $this->checkDebugDisabled(),
            'debug',
            'Debug mode',
            ['key' => 'APP_DEBUG', 'value' => 'false'],
        );
        $add($this->checkAppKey(), 'app_key', 'Application key');
        $add(
            $this->checkSessionEncrypted(),
            'session_encrypt',
            'Encrypted session payloads',
            ['key' => 'SESSION_ENCRYPT', 'value' => 'true'],
        );
        $add($this->checkPlatformAdminTwoFactor(), 'admin_2fa', 'Platform admin two-factor sign-in');
        $add($this->checkBackupArchivePassword(), 'backup_archive_password', 'Backup archive password');
        $add($this->checkAppUrlIsHttps(), 'app_url', 'HTTPS application URL');
        $add($this->checkDomainsConfigured(), 'domains', 'Platform domains');
        $add(
            $this->checkSessionSecureCookie(),
            'session_secure',
            'Secure session cookies',
            ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true'],
        );
        $add(
            $this->checkSessionDomain(),
            'session_domain',
            'Host-only session cookies',
            ['key' => 'SESSION_DOMAIN', 'value' => ''],
        );

        foreach (config('preflight.writable_paths', []) as $path) {
            $path = (string) $path;
            $add(
                is_writable($path) ? null : "Not writable: {$path}",
                "writable:{$path}",
                "Writable: {$path}",
            );
        }

        $add($this->checkDatabaseReachable(), 'database', 'Database reachable');
        $add($this->checkMysqlVersion(), 'mysql', 'Database version');
        $add($this->checkNoDemoData(), 'demo_data', 'No demo data');
        $add($this->checkNoLocalhostDemoDomain(), 'localhost_demo', 'No demo.localhost domain');
        $add(
            $this->checkVideoDriver(),
            'video_driver',
            'Video driver',
            config('coaching.video_driver') === 'fake' ? ['key' => 'VIDEO_DRIVER', 'value' => 'bunny'] : null,
        );
        $add($this->checkGdFreetype(), 'gd', 'GD FreeType');

        return $outcomes;
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
     * `null` (what .env.example ships) and `''` (what docs/DEPLOY.md's `SESSION_DOMAIN=`
     * yields) both produce a host-only session cookie; anything else would make the
     * browser send one tenant's session cookie to every other tenant subdomain, which
     * defeats tenant isolation before any application code runs (SECURITY.md). This
     * check was always described in docs/DEPLOY.md §3 but was never actually implemented
     * — added by the Phase 11 mini security pass.
     */
    /**
     * Production only: session payloads at rest must be encrypted (SESSION_ENCRYPT=true).
     */
    private function checkSessionEncrypted(): ?string
    {
        if (! app()->isProduction()) {
            return null;
        }

        return config('session.encrypt') === true
            ? null
            : 'SESSION_ENCRYPT must be true in production, so session payloads are encrypted at rest.';
    }

    /**
     * Production only: backup archives are encrypted with BACKUP_ARCHIVE_PASSWORD. An empty
     * password would write unencrypted archives.
     */
    private function checkBackupArchivePassword(): ?string
    {
        if (! app()->isProduction()) {
            return null;
        }

        return filled(config('backup.backup.password'))
            ? null
            : 'BACKUP_ARCHIVE_PASSWORD is empty, so backup archives would not be encrypted.';
    }

    /**
     * Production only: every platform admin must have a two-factor secret. The message names the
     * admins so the operator knows whom to enrol.
     */
    private function checkPlatformAdminTwoFactor(): ?string
    {
        if (! app()->isProduction()) {
            return null;
        }

        try {
            if (! Schema::hasTable('platform_admins')) {
                return null;
            }

            $missing = PlatformAdmin::query()->whereNull('totp_secret')->pluck('email')->all();
        } catch (Throwable) {
            // An unreachable database is reported by checkDatabaseReachable(), not here.
            return null;
        }

        return $missing === []
            ? null
            : 'Platform admins without two-factor sign-in: '.implode(', ', $missing).'. Each must enrol at /admin/two-factor/setup.';
    }

    private function checkSessionDomain(): ?string
    {
        $domain = config('session.domain');

        if ($domain === null || $domain === '') {
            return null;
        }

        return "SESSION_DOMAIN is set (config: {$domain}) — it must be empty/null so session cookies stay host-only. "
            ."A shared cookie domain would send one tenant's session cookie to every other tenant subdomain (SECURITY.md).";
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
        // Seam (config/preflight.php, mirrors the GD one): when forced, this value is
        // evaluated as the server's version *including* on SQLite — the driver gate below
        // would otherwise make the below-minimum branch unreachable by any test (SP-9).
        $forced = config('preflight.forced_database_version');

        if ($forced !== null) {
            return DatabaseVersionCheck::minimumViolationMessage($forced);
        }

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

        if ($driver === 'bunny' && ! ServiceSettings::isConfigured('bunny_account_api_key')) {
            return 'VIDEO_DRIVER=bunny but BUNNY_STREAM_ACCOUNT_API_KEY is not set.';
        }

        return null;
    }

}
