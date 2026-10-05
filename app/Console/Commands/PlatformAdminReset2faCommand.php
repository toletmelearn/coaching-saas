<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;

/**
 * Break-glass recovery for a platform admin who has lost both their authenticator and every
 * recovery code. Run on the server by someone with shell access, never over HTTP.
 */
class PlatformAdminReset2faCommand extends Command
{
    protected $signature = 'platform-admin:reset-2fa {email}';

    protected $description = 'Remove a platform admin two-factor secret and recovery codes';

    public function handle(): int
    {
        $admin = PlatformAdmin::where('email', $this->argument('email'))->first();

        if ($admin === null) {
            $this->error('No platform admin with that email.');

            return self::FAILURE;
        }

        $admin->forceFill([
            'totp_secret' => null,
            'totp_enabled_at' => null,
            'totp_last_step' => null,
            'recovery_codes' => null,
        ])->save();

        $this->info('Two-factor authentication reset. The admin will be asked to enrol again.');

        return self::SUCCESS;
    }
}
