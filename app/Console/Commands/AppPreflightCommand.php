<?php

namespace App\Console\Commands;

use App\Support\Admin\ServiceSettings;
use App\Support\Preflight\PreflightChecks;
use Illuminate\Console\Command;

class AppPreflightCommand extends Command
{
    protected $signature = 'app:preflight
                            {--require-production : Fail with exit code 2 when APP_ENV is not production, instead of the informational no-op (deploys should always pass this — docs/DEPLOY_RUNBOOK.md §6.1)}';

    protected $description = 'Verify the production environment is safely configured before/after a deploy.';

    public function handle(): int
    {
        if (! app()->environment('production')) {
            // Without --require-production this stays the informational no-op it has always
            // been (exit 0), so local/dev runs are unchanged. With the flag — which the
            // deploy runbook passes — a forgotten APP_ENV=production fails loudly instead of
            // printing a line nobody is forced to read (PROJECT_BRIEF §6, SP-7). Exit code
            // is INVALID (2), not FAILURE (1): 1 means "a real preflight check failed",
            // 2 means "you are not even measuring the production environment".
            if ($this->option('require-production')) {
                $this->components->error(sprintf(
                    'APP_ENV is "%s", not "production" — this command was run with --require-production.',
                    app()->environment(),
                ));

                return self::INVALID;
            }

            $this->components->info('Not running in production — preflight checks are informational only here.');

            return self::SUCCESS;
        }

        // The checks themselves live in PreflightChecks (extracted in Phase 13 so
        // /admin/health runs this exact code) — this command owns only the production
        // gate, the formatting and the exit code. Failure messages are unchanged.
        $failures = array_filter(array_map(
            static fn (array $outcome): ?string => $outcome['passed'] ? null : $outcome['message'],
            app(PreflightChecks::class)->run(),
        ));

        if ($failures === []) {
            $this->components->info('All preflight checks passed.');

            if ((bool) config('coaching.live_classes_enabled')) {
                // Visible proof the check actually saw the configured app id
                // (LiveClassPreflightTest requires "Jitsi" in a passing run too).
                $this->components->info(
                    'Jitsi live classes enabled — JaaS app id '.ServiceSettings::get('jitsi_app_id').' configured.'
                );
            }

            return self::SUCCESS;
        }

        $this->components->error(sprintf('%d preflight check(s) failed:', count($failures)));

        foreach ($failures as $failure) {
            $this->components->twoColumnDetail($failure, '<fg=red>FAIL</>');
        }

        return self::FAILURE;
    }
}
