<?php

namespace App\Traits;

use App\Exceptions\InvalidTenantException;
use App\Exceptions\MissingTenantContextException;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (! $context->has()) {
                throw new MissingTenantContextException(
                    sprintf('Cannot create %s without a tenant context', $model::class)
                );
            }

            $contextTenantId = $context->id();

            if ($model->tenant_id && (int) $model->tenant_id !== $contextTenantId) {
                throw new InvalidTenantException(
                    sprintf(
                        'Cannot create %s with tenant_id %d when context is %d',
                        $model::class,
                        (int) $model->tenant_id,
                        $contextTenantId
                    )
                );
            }

            $model->tenant_id = $contextTenantId;
        });

        static::updating(function ($model) {
            $original = (int) $model->getOriginal('tenant_id');
            $current = (int) $model->getAttribute('tenant_id');

            if ($original !== $current) {
                throw new InvalidTenantException(
                    sprintf(
                        'Cannot change tenant_id on %s (original: %d, attempted: %d)',
                        $model::class,
                        $original,
                        $current
                    )
                );
            }
        });

        static::saving(function ($model) {
            $context = app(TenantContext::class);

            if (! $context->has()) {
                throw new MissingTenantContextException(
                    sprintf('Cannot save %s without a tenant context', $model::class)
                );
            }

            $contextTenantId = $context->id();

            // Auto-fill tenant_id if not yet set (e.g., when using make() then save())
            if (! $model->tenant_id) {
                $model->tenant_id = $contextTenantId;

                return;
            }

            // If tenant_id is already set, it must match the context
            if ((int) $model->tenant_id !== $contextTenantId) {
                throw new InvalidTenantException(
                    sprintf(
                        'Cannot save %s with tenant_id %d when context is %d',
                        $model::class,
                        (int) $model->tenant_id,
                        $contextTenantId
                    )
                );
            }
        });

        static::deleting(function ($model) {
            $context = app(TenantContext::class);

            if (! $context->has()) {
                throw new MissingTenantContextException(
                    sprintf('Cannot delete %s without a tenant context', $model::class)
                );
            }

            if ((int) $model->tenant_id !== $context->id()) {
                throw new InvalidTenantException(
                    sprintf(
                        'Cannot delete %s with tenant_id %d when context is %d',
                        $model::class,
                        (int) $model->tenant_id,
                        $context->id()
                    )
                );
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function getTenantIdColumn(): string
    {
        return 'tenant_id';
    }
}
