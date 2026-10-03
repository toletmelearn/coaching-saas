<?php

namespace App\Console\Commands;

use App\Enums\LiveClassStatus;
use App\Models\LiveClass;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12 — the live-class state machine, run every minute.
 *
 * scheduled -> live once starts_at has passed; live -> ended once the class's
 * effective end (ends_at, or starts_at + 90 minutes when none was given) has
 * passed. Cancelled and ended rows are never touched — cancel is terminal and
 * ended is history.
 *
 * Status is the single source of truth every join/heartbeat gate reads, which
 * is why this runs on the scheduler rather than being inferred per request:
 * one place decides, one place can be audited.
 */
class LiveClassesUpdateStatusCommand extends Command
{
    protected $signature = 'live-classes:update-status';

    protected $description = 'Transition live-class statuses: scheduled to live at start time, live to ended at end time (Phase 12).';

    public function handle(TenantContext $tenantContext): int
    {
        // Raw query across all tenants — a scheduled system command, not a
        // per-tenant request, so there is no ambient context to scope through
        // (the same allowed exception videos:sync uses). Every row is then
        // processed inside runAs() of its own tenant before any model with a
        // TenantScope is touched.
        $candidates = DB::table('live_classes')
            ->whereIn('status', [LiveClassStatus::Scheduled->value, LiveClassStatus::Live->value])
            ->get(['id', 'tenant_id']);

        $transitioned = 0;

        foreach ($candidates as $candidate) {
            $tenant = Tenant::find($candidate->tenant_id);

            if ($tenant === null) {
                continue;
            }

            $transitioned += (int) $tenantContext->runAs($tenant, function () use ($candidate) {
                $class = LiveClass::find($candidate->id);

                if ($class === null) {
                    return 0;
                }

                $now = now();

                if ($class->status === LiveClassStatus::Scheduled && $class->starts_at->lte($now)) {
                    $class->forceFill(['status' => LiveClassStatus::Live])->save();

                    return 1;
                }

                if ($class->status === LiveClassStatus::Live && $class->effectiveEndsAt()->lte($now)) {
                    $class->forceFill(['status' => LiveClassStatus::Ended])->save();

                    return 1;
                }

                return 0;
            });
        }

        $this->info("Transitioned {$transitioned} live class(es).");

        return self::SUCCESS;
    }
}
