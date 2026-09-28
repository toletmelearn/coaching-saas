<?php

namespace App\Database;

use App\Exceptions\InvalidTenantException;
use App\Exceptions\MissingTenantContextException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

class TenantBuilder extends Builder
{
    public function update(array $values)
    {
        if ($this->containsNormalisedKey('tenant_id', $values)) {
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

        if (is_array($update) && $this->containsNormalisedKey('tenant_id', $update)) {
            throw new InvalidTenantException(
                'Cannot upsert with tenant_id via query builder — use model instance and BelongsToTenant hooks'
            );
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    public function insert(array $values)
    {
        $context = app(TenantContext::class);

        if (! $context->has()) {
            throw new MissingTenantContextException(
                'Cannot insert rows without a tenant context'
            );
        }

        $contextTenantId = $context->id();

        foreach ($values as &$row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->hasNormalisedKey('tenant_id', $row)) {
                $tenantIdValue = $this->getNormalisedValue('tenant_id', $row);
                if ((int) $tenantIdValue !== $contextTenantId) {
                    throw new InvalidTenantException(
                        'Cannot insert row with tenant_id '.$tenantIdValue.' when context is '.$contextTenantId
                    );
                }
            } else {
                $row['tenant_id'] = $contextTenantId;
            }
        }

        return parent::insert($values);
    }

    public function insertGetId(array $values, $sequence = null)
    {
        $context = app(TenantContext::class);

        if (! $context->has()) {
            throw new MissingTenantContextException(
                'Cannot insert row without a tenant context'
            );
        }

        $contextTenantId = $context->id();

        if ($this->hasNormalisedKey('tenant_id', $values)) {
            $tenantIdValue = $this->getNormalisedValue('tenant_id', $values);
            if ((int) $tenantIdValue !== $contextTenantId) {
                throw new InvalidTenantException(
                    'Cannot insert row with tenant_id '.$tenantIdValue.' when context is '.$contextTenantId
                );
            }
        } else {
            $values['tenant_id'] = $contextTenantId;
        }

        return parent::insertGetId($values, $sequence);
    }

    public function insertOrIgnore(array $values)
    {
        $context = app(TenantContext::class);

        if (! $context->has()) {
            throw new MissingTenantContextException(
                'Cannot insert row without a tenant context'
            );
        }

        $contextTenantId = $context->id();

        foreach ($values as &$row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->hasNormalisedKey('tenant_id', $row)) {
                $tenantIdValue = $this->getNormalisedValue('tenant_id', $row);
                if ((int) $tenantIdValue !== $contextTenantId) {
                    throw new InvalidTenantException(
                        'Cannot insert row with tenant_id '.$tenantIdValue.' when context is '.$contextTenantId
                    );
                }
            } else {
                $row['tenant_id'] = $contextTenantId;
            }
        }

        return parent::insertOrIgnore($values);
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        if ($this->containsNormalisedKey('tenant_id', $extra)) {
            throw new InvalidTenantException(
                'Cannot increment with tenant_id in extra columns — use model instance and BelongsToTenant hooks'
            );
        }

        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        if ($this->containsNormalisedKey('tenant_id', $extra)) {
            throw new InvalidTenantException(
                'Cannot decrement with tenant_id in extra columns — use model instance and BelongsToTenant hooks'
            );
        }

        return parent::decrement($column, $amount, $extra);
    }

    public function incrementEach(array $columns, $extra = [])
    {
        if ($this->containsNormalisedKey('tenant_id', $columns)) {
            throw new InvalidTenantException(
                'Cannot incrementEach with tenant_id — use model instance and BelongsToTenant hooks'
            );
        }

        if ($this->containsNormalisedKey('tenant_id', $extra)) {
            throw new InvalidTenantException(
                'Cannot incrementEach with tenant_id in extra — use model instance and BelongsToTenant hooks'
            );
        }

        return parent::incrementEach($columns, $extra);
    }

    public function decrementEach(array $columns, $extra = [])
    {
        if ($this->containsNormalisedKey('tenant_id', $columns)) {
            throw new InvalidTenantException(
                'Cannot decrementEach with tenant_id — use model instance and BelongsToTenant hooks'
            );
        }

        if ($this->containsNormalisedKey('tenant_id', $extra)) {
            throw new InvalidTenantException(
                'Cannot decrementEach with tenant_id in extra — use model instance and BelongsToTenant hooks'
            );
        }

        return parent::decrementEach($columns, $extra);
    }

    private function normalisedKey(string $key): string
    {
        $lower = strtolower($key);

        return substr($lower, strrpos($lower, '.') + 1);
    }

    private function containsNormalisedKey(string $key, array $values): bool
    {
        $normalisedKey = $this->normalisedKey($key);

        foreach (array_keys($values) as $valueKey) {
            if ($this->normalisedKey((string) $valueKey) === $normalisedKey) {
                return true;
            }
        }

        return false;
    }

    private function hasNormalisedKey(string $key, array $row): bool
    {
        $normalisedKey = $this->normalisedKey($key);

        foreach (array_keys($row) as $rowKey) {
            if ($this->normalisedKey((string) $rowKey) === $normalisedKey) {
                return true;
            }
        }

        return false;
    }

    private function getNormalisedValue(string $key, array $row): mixed
    {
        $normalisedKey = $this->normalisedKey($key);

        foreach ($row as $rowKey => $value) {
            if ($this->normalisedKey((string) $rowKey) === $normalisedKey) {
                return $value;
            }
        }

        return null;
    }

    private function hasKeyInAnyRow(string $key, array $values): bool
    {
        foreach ($values as $row) {
            if (is_array($row) && $this->hasNormalisedKey($key, $row)) {
                return true;
            }
        }

        return false;
    }
}
