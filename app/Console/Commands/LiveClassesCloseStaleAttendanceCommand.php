<?php

namespace App\Console\Commands;

use App\Models\LiveClassAttendance;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12 — closes attendance rows whose heartbeats went quiet.
 *
 * A student who tabs away stops beaconing; without this sweep their row would
 * stay "open" forever and the next heartbeat would resume it minutes later,
 * crediting a gap the student never sat through. left_at is set to the row's
 * own last_seen_at — the last moment the server actually saw them — never to
 * "now", so a stalled connection cannot inflate the stay.
 */
class LiveClassesCloseStaleAttendanceCommand extends Command
{
    protected $signature = 'live-classes:close-stale-attendance';

    protected $description = 'Close live-class attendance rows with no heartbeat for the stale window (default 5 minutes).';

    public function handle(TenantContext $tenantContext): int
    {
        $threshold = now()->subMinutes((int) config('coaching.live_class_stale_minutes', 5));

        // Raw query across all tenants — same system-level exception as
        // live-classes:update-status and videos:sync; each row is re-entered
        // through runAs() of its own tenant before the model layer sees it.
        $stale = DB::table('live_class_attendance')
            ->whereNull('left_at')
            ->where('last_seen_at', '<=', $threshold)
            ->get(['id', 'tenant_id']);

        $closed = 0;

        foreach ($stale as $row) {
            $tenant = Tenant::find($row->tenant_id);

            if ($tenant === null) {
                continue;
            }

            $closed += (int) $tenantContext->runAs($tenant, function () use ($row) {
                $attendance = LiveClassAttendance::find($row->id);

                if ($attendance === null) {
                    return 0;
                }

                $attendance->forceFill(['left_at' => $attendance->last_seen_at])->save();

                return 1;
            });
        }

        $this->info("Closed {$closed} stale attendance row(s).");

        return self::SUCCESS;
    }
}
