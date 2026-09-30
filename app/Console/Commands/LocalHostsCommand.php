<?php

namespace App\Console\Commands;

use App\Models\TenantDomain;
use Illuminate\Console\Command;

/**
 * A convenience command only — never allowed in production, since a production server
 * doesn't use a local hosts file at all (real DNS + Cloudflare, see docs/DEPLOY.md).
 */
class LocalHostsCommand extends Command
{
    protected $signature = 'local:hosts';

    protected $description = 'Print hosts-file lines (127.0.0.1 <domain>) for every tenant domain plus the central domains, for local Windows hosts-file setup.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('local:hosts only runs in local/testing environments.');

            return self::FAILURE;
        }

        $domains = collect(config('tenancy.central_domains', []))
            // localhost/127.0.0.1 already resolve without a hosts-file entry.
            ->reject(fn (string $domain) => in_array($domain, ['localhost', '127.0.0.1'], true))
            ->merge(TenantDomain::query()->orderBy('domain')->pluck('domain'))
            ->unique()
            ->values();

        foreach ($domains as $domain) {
            $this->line("127.0.0.1 {$domain}");
        }

        return self::SUCCESS;
    }
}
