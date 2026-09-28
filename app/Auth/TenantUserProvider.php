<?php

namespace App\Auth;

use App\Exceptions\MissingTenantContextException;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A tenant-aware user provider.
 *
 * The User model's BelongsToTenant global scope already restricts every query to the
 * tenant resolved from the request hostname (see TENANCY.md). This provider only adds
 * one thing on top of that: when no tenant context is set at all (e.g. a request to the
 * central domain), the scope would throw MissingTenantContextException — but for an auth
 * provider that must resolve to "no user" (guest), not an application error.
 */
class TenantUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        try {
            return parent::retrieveById($identifier);
        } catch (MissingTenantContextException) {
            return null;
        }
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        try {
            return parent::retrieveByToken($identifier, $token);
        } catch (MissingTenantContextException) {
            return null;
        }
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        try {
            return parent::retrieveByCredentials($credentials);
        } catch (MissingTenantContextException) {
            return null;
        }
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
        try {
            parent::updateRememberToken($user, $token);
        } catch (MissingTenantContextException) {
            // No tenant context to persist against; nothing to do.
        }
    }
}
