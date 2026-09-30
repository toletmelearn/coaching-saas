<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily database + private-storage backup, then prune anything past the 14-day retention
// window (config/backup.php). Requires the server cron -> schedule:run (docs/DEPLOY.md).
Schedule::command('backup:run')->daily()->at('02:00')->onOneServer();
Schedule::command('backup:clean')->daily()->at('01:30')->onOneServer();

// Polls the video provider for videos still uploading/processing (no webhooks yet —
// see VIDEO.md). Every minute per docs/specs/phase-5-video.md.
Schedule::command('videos:sync')->everyMinute()->onOneServer();

// Deletes device rows not seen for 60+ days (Phase 8 — see docs/specs/phase-8-devices.md).
Schedule::command('devices:prune')->daily()->onOneServer();
