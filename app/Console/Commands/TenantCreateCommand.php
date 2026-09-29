<?php

namespace App\Console\Commands;

use App\Enums\DomainType;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\TemporaryPasswordGenerator;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class TenantCreateCommand extends Command
{
    protected $signature = 'tenant:create
        {name : The institute/tenant display name}
        {subdomain : e.g. "brightfuture" for brightfuture.<tenant base domain>}
        {--owner-name= : The owner\'s full name}
        {--owner-email= : The owner\'s email address}
        {--owner-phone= : The owner\'s phone number}';

    protected $description = 'Provision a new tenant: the tenant, its subdomain, and an owner account with a temporary password.';

    private const RESERVED_SUBDOMAINS = [
        'www', 'admin', 'api', 'app', 'mail', 'staging', 'support',
        'help', 'status', 'cdn', 'assets', 'static', 'demo',
    ];

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $subdomain = strtolower(trim((string) $this->argument('subdomain')));
        $ownerName = trim((string) $this->option('owner-name'));
        $ownerEmail = $this->option('owner-email') ? strtolower(trim((string) $this->option('owner-email'))) : null;
        $ownerPhone = $this->option('owner-phone') ? trim((string) $this->option('owner-phone')) : null;

        if ($name === '') {
            $this->components->error('The tenant name cannot be empty.');

            return self::FAILURE;
        }

        if (! $this->validateSubdomain($subdomain)) {
            return self::FAILURE;
        }

        if ($ownerName === '') {
            $this->components->error('--owner-name is required.');

            return self::FAILURE;
        }

        if ($ownerEmail === null && $ownerPhone === null) {
            $this->components->error('Provide --owner-email or --owner-phone (or both).');

            return self::FAILURE;
        }

        $baseDomain = config('tenancy.tenant_base_domain');
        $domain = "{$subdomain}.{$baseDomain}";

        if (TenantDomain::query()->where('domain', $domain)->exists()) {
            $this->components->error("The subdomain \"{$subdomain}\" is already in use.");

            return self::FAILURE;
        }

        $tenant = Tenant::query()->create([
            'name' => $name,
            'status' => TenantStatus::Active,
        ]);

        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'domain' => $domain,
            'type' => DomainType::Subdomain,
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        $temporaryPassword = TemporaryPasswordGenerator::generate();

        app(TenantContext::class)->runAs($tenant, function () use ($ownerName, $ownerEmail, $ownerPhone, $temporaryPassword) {
            $owner = new User([
                'name' => $ownerName,
                'email' => $ownerEmail,
                'phone' => $ownerPhone,
            ]);
            $owner->forceFill([
                'role' => UserRole::Owner,
                'status' => UserStatus::Active,
                'must_change_password' => true,
                'password' => Hash::make($temporaryPassword),
            ]);
            $owner->save();
        });

        $this->components->info("Tenant \"{$name}\" created at https://{$domain}");
        $this->line('  Owner login: '.($ownerEmail ?? $ownerPhone));
        $this->line("  Temporary password: {$temporaryPassword}");
        $this->components->warn('This password is shown once — copy it now.');

        return self::SUCCESS;
    }

    private function validateSubdomain(string $subdomain): bool
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/', $subdomain)) {
            $this->components->error(
                'Subdomain must be 3-30 characters, lowercase letters/digits/hyphens only, and not start or end with a hyphen.'
            );

            return false;
        }

        if (in_array($subdomain, self::RESERVED_SUBDOMAINS, true)) {
            $this->components->error("\"{$subdomain}\" is a reserved subdomain and cannot be used.");

            return false;
        }

        return true;
    }
}
