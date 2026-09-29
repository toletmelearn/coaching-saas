<?php

namespace App\Console\Commands;

use App\Contracts\VideoProvider;
use App\Enums\VideoStatus;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VideosSyncCommand extends Command
{
    protected $signature = 'videos:sync';

    protected $description = 'Poll the video provider for videos still uploading/processing and update their status.';

    public function handle(TenantContext $tenantContext, VideoProvider $provider): int
    {
        // Raw query across all tenants — a scheduled system command, not a per-tenant
        // request, so there is no ambient TenantContext to scope through (TENANCY.md:
        // console commands are one of the allowed system-level exceptions). Every row's
        // own tenant_id is used below via TenantContext::runAs() before it is touched.
        $rows = DB::table('lesson_videos')
            ->whereIn('status', [VideoStatus::Uploading->value, VideoStatus::Processing->value])
            ->get(['id', 'tenant_id']);

        foreach ($rows as $row) {
            $tenant = Tenant::find($row->tenant_id);

            if ($tenant === null) {
                continue;
            }

            $tenantContext->runAs($tenant, function () use ($row, $provider) {
                $video = LessonVideo::find($row->id);

                if ($video === null) {
                    return;
                }

                $result = $provider->fetchStatus($video);

                $video->forceFill([
                    'status' => $result['status'],
                    'duration_seconds' => $result['duration_seconds'] ?? $video->duration_seconds,
                    'error_message' => $result['error_message'] ?? null,
                ])->save();
            });
        }

        return self::SUCCESS;
    }
}
