<?php

namespace App\Actions;

use App\Enums\DomainType;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\TemporaryPasswordGenerator;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single source of truth for "create a tenant + its domain + an owner account",
 * used identically by `php artisan tenant:create` and the platform admin's
 * create-institute form — same validation, same reserved-subdomain list, same
 * duplicate check, so the two paths can never drift apart.
 */
class CreateInstituteAction
{
    private const RESERVED_SUBDOMAINS = [
        'www', 'admin', 'api', 'app', 'mail', 'staging', 'support',
        'help', 'status', 'cdn', 'assets', 'static', 'demo',
    ];

    /**
     * @param  array{name: string, subdomain: string, owner_name: string, owner_email: ?string, owner_phone: ?string}  $data
     * @return array{tenant: Tenant, domain: string, owner: User, temporary_password: string}
     *
     * @throws ValidationException
     */
    public function execute(array $data): array
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'subdomain' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['nullable', 'email', 'max:255'],
            'owner_phone' => ['nullable', 'string', 'max:20'],
        ], [
            'subdomain.regex' => __('platform.admin.institutes.errors.subdomain_format'),
        ])->validate();

        $ownerEmail = $validated['owner_email'] ?? null;
        $ownerPhone = $validated['owner_phone'] ?? null;

        if ($ownerEmail === null && $ownerPhone === null) {
            throw ValidationException::withMessages([
                'owner_email' => __('platform.admin.institutes.errors.owner_contact_required'),
                'owner_phone' => __('platform.admin.institutes.errors.owner_contact_required'),
            ]);
        }

        $subdomain = strtolower($validated['subdomain']);

        if (in_array($subdomain, self::RESERVED_SUBDOMAINS, true)) {
            throw ValidationException::withMessages([
                'subdomain' => __('platform.admin.institutes.errors.subdomain_reserved'),
            ]);
        }

        $baseDomain = config('tenancy.tenant_base_domain');
        $domain = "{$subdomain}.{$baseDomain}";

        if (TenantDomain::query()->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages([
                'subdomain' => __('platform.admin.institutes.errors.subdomain_taken'),
            ]);
        }

        $tenant = Tenant::query()->create([
            'name' => $validated['name'],
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

        $owner = app(TenantContext::class)->runAs($tenant, function () use ($validated, $ownerEmail, $ownerPhone, $temporaryPassword) {
            $owner = new User([
                'name' => $validated['owner_name'],
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

            return $owner;
        });

        return [
            'tenant' => $tenant,
            'domain' => $domain,
            'owner' => $owner,
            'temporary_password' => $temporaryPassword,
        ];
    }
}
