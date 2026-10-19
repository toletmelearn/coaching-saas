<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\TemporaryPasswordGenerator;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UserCreateOwnerCommand extends Command
{
    protected $signature = 'user:create-owner
        {tenant : Tenant ID or subdomain (e.g. "brightfuture" or "1")}
        {--name= : Owner\'s full name}
        {--email= : Owner\'s email address}
        {--phone= : Owner\'s phone number}';

    protected $description = 'Create an owner account in an existing tenant.';

    public function handle(TenantContext $context): int
    {
        $tenantArg = (string) $this->argument('tenant');

        $tenant = is_numeric($tenantArg)
            ? Tenant::query()->find((int) $tenantArg)
            : $this->findBySubdomain($tenantArg);

        if ($tenant === null) {
            $this->components->error("No tenant found for \"{$tenantArg}\".");

            return self::FAILURE;
        }

        $name = $this->option('name') ? trim((string) $this->option('name')) : trim((string) $this->ask('Owner name'));
        $email = $this->option('email') ? strtolower(trim((string) $this->option('email'))) : null;
        $phone = $this->option('phone') ? trim((string) $this->option('phone')) : null;

        if ($email === null && $phone === null) {
            $email = strtolower(trim((string) $this->ask('Owner email (or leave blank to use phone)')));
            if ($email === '') {
                $email = null;
                $phone = trim((string) $this->ask('Owner phone'));
            }
        }

        try {
            Validator::make([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
            ])->validate();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->components->error($messages[0]);
            }

            return self::FAILURE;
        }

        // When $email is null here, $phone is always a non-nullable string (the interactive-ask
        // block or option assignment above guarantees it). Reject if it is also blank.
        if ($email === null && $phone === '') {
            $this->components->error('Provide at least one of --email or --phone.');

            return self::FAILURE;
        }

        $temporaryPassword = TemporaryPasswordGenerator::generate();

        $owner = $context->runAs($tenant, function () use ($name, $email, $phone, $temporaryPassword) {
            $owner = new User([
                'name' => $name,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
            ]);
            $owner->forceFill([
                'role' => UserRole::Owner,
                'status' => UserStatus::Active,
                'must_change_password' => true,
                'password' => Hash::make($temporaryPassword),
            ]);
            $owner->save();

            return $owner;
        });

        $this->components->info("Owner \"{$owner->name}\" created in tenant \"{$tenant->name}\".");
        $this->line('  Login: '.($owner->email ?? $owner->phone));
        $this->line("  Temporary password: {$temporaryPassword}");
        $this->components->warn('This password is shown once — copy it now.');

        return self::SUCCESS;
    }

    private function findBySubdomain(string $subdomain): ?Tenant
    {
        $baseDomain = config('tenancy.tenant_base_domain');
        $domain = str_contains($subdomain, '.')
            ? $subdomain
            : "{$subdomain}.{$baseDomain}";

        $tenantDomain = TenantDomain::query()->where('domain', $domain)->first();

        return $tenantDomain?->tenant;
    }
}
