<?php

namespace App\Support;

use App\Models\Tenant;
use RuntimeException;

final class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $set = false;

    public function set(Tenant $tenant): void
    {
        if ($this->set) {
            throw new RuntimeException('TenantContext is already set for this request.');
        }

        $this->tenant = $tenant;
        $this->set = true;
    }

    public function has(): bool
    {
        return $this->set && $this->tenant !== null;
    }

    public function get(): Tenant
    {
        if (! $this->has()) {
            throw new RuntimeException('TenantContext has not been set.');
        }

        return $this->tenant;
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function runAs(Tenant $tenant, callable $fn): mixed
    {
        if ($this->set) {
            throw new RuntimeException('Cannot runAs() when a tenant is already set.');
        }

        $this->set($tenant);

        try {
            return $fn();
        } finally {
            $this->tenant = null;
            $this->set = false;
        }
    }
}
