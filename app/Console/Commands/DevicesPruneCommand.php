<?php

namespace App\Console\Commands;

use App\Models\UserDevice;
use Illuminate\Console\Command;

class DevicesPruneCommand extends Command
{
    protected $signature = 'devices:prune';

    protected $description = 'Delete device rows not seen for 60 days or more';

    public function handle(): int
    {
        // System-level maintenance command: prunes stale device rows across every
        // tenant in one pass, not just the (nonexistent) ambient tenant context of a
        // console run. Documented exception per TENANCY.md ("System-level operations:
        // migrations, seeders, admin console commands").
        $deleted = UserDevice::withoutGlobalScopes()
            ->where('last_seen_at', '<', now()->subDays(60))
            ->delete();

        $this->info("Pruned {$deleted} stale device row(s).");

        return self::SUCCESS;
    }
}
