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

    /**
     * Clear the context so a new top-level request starts clean.
     *
     * Outside of tests, this is a no-op in practice: each HTTP request gets a fresh
     * container in the traditional one-process-per-request model. It exists so that
     * environments where the container persists across requests (the test HTTP client,
     * Octane) don't leak one request's tenant into the next. Only ResolveTenant, at the
     * very start of the middleware pipeline, should call this — never application code.
     */
    public function reset(): void
    {
        $this->tenant = null;
        $this->set = false;
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
