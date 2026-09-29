<?php

namespace App\Console\Commands;

use App\Actions\CreateInstituteAction;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class TenantCreateCommand extends Command
{
    protected $signature = 'tenant:create
        {name : The institute/tenant display name}
        {subdomain : e.g. "brightfuture" for brightfuture.<tenant base domain>}
        {--owner-name= : The owner\'s full name}
        {--owner-email= : The owner\'s email address}
        {--owner-phone= : The owner\'s phone number}';

    protected $description = 'Provision a new tenant: the tenant, its subdomain, and an owner account with a temporary password.';

    public function handle(CreateInstituteAction $action): int
    {
        try {
            $result = $action->execute([
                'name' => trim((string) $this->argument('name')),
                'subdomain' => strtolower(trim((string) $this->argument('subdomain'))),
                'owner_name' => trim((string) $this->option('owner-name')),
                'owner_email' => $this->option('owner-email') ? strtolower(trim((string) $this->option('owner-email'))) : null,
                'owner_phone' => $this->option('owner-phone') ? trim((string) $this->option('owner-phone')) : null,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->components->error($messages[0]);
            }

            return self::FAILURE;
        }

        $this->components->info("Tenant \"{$result['tenant']->name}\" created at https://{$result['domain']}");
        $this->line('  Owner login: '.($result['owner']->email ?? $result['owner']->phone));
        $this->line("  Temporary password: {$result['temporary_password']}");
        $this->components->warn('This password is shown once — copy it now.');

        return self::SUCCESS;
    }
}
