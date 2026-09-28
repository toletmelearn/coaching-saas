<?php

namespace App\Database;

use App\Exceptions\InvalidTenantException;
use Illuminate\Database\Eloquent\Builder;

class TenantBuilder extends Builder
{
    public function update(array $values)
    {
        if (array_key_exists('tenant_id', $values)) {
            throw new InvalidTenantException(
                'Cannot update tenant_id via query builder — use model instance and BelongsToTenant hooks'
            );
        }

        return parent::update($values);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if ($this->hasKeyInAnyRow('tenant_id', $values)) {
            throw new InvalidTenantException(
                'Cannot upsert with tenant_id via query builder — use model instance and BelongsToTenant hooks'
            );
        }

        if (is_array($update) && array_key_exists('tenant_id', $update)) {
            throw new InvalidTenantException(
                'Cannot upsert with tenant_id via query builder — use model instance and BelongsToTenant hooks'
            );
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    private function hasKeyInAnyRow(string $key, array $values): bool
    {
        foreach ($values as $row) {
            if (is_array($row) && array_key_exists($key, $row)) {
                return true;
            }
        }

        return false;
    }
}
