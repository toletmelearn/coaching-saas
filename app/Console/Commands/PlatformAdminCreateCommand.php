<?php

namespace App\Console\Commands;

use App\Enums\PlatformAdminStatus;
use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PlatformAdminCreateCommand extends Command
{
    protected $signature = 'platform-admin:create';

    protected $description = 'Create a platform admin account.';

    public function handle(): int
    {
        $name = trim((string) $this->ask('Name'));

        if ($name === '') {
            $this->components->error('Name cannot be empty.');

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->ask('Email')));

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);

        if ($validator->fails()) {
            $this->components->error('Enter a valid email address.');

            return self::FAILURE;
        }

        if (PlatformAdmin::query()->where('email', $email)->exists()) {
            $this->components->error("A platform admin with email \"{$email}\" already exists.");

            return self::FAILURE;
        }

        $password = $this->secret('Password (min 12 characters)');

        if (! is_string($password) || strlen($password) < 12) {
            $this->components->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $confirmation = $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->components->error('Passwords do not match.');

            return self::FAILURE;
        }

        PlatformAdmin::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'status' => PlatformAdminStatus::Active,
        ]);

        $this->components->info("Platform admin \"{$name}\" ({$email}) created.");

        return self::SUCCESS;
    }
}
