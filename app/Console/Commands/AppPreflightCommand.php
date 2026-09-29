<?php

namespace App\Console\Commands;

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
                || TenantDomain::query()->where('domain', "demo.{$baseDomain}")->exists();
        } catch (Throwable) {
            // Already reported by checkDatabaseReachable(); don't double-report.
            return null;
        }

        return $demoExists
            ? 'A demo tenant/account exists — never seed demo data in production (see README.md "Local credentials" and docs/DEPLOY.md).'
            : null;
    }
}
