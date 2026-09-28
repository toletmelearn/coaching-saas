<?php

namespace App\Scopes;

use App\Exceptions\MissingTenantContextException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->has()) {
            throw new MissingTenantContextException(
                sprintf('Cannot query %s without a tenant context', $model::class)
            );
        }

        $builder->where($model->qualifyColumn('tenant_id'), '=', (int) $context->id());
    }
}
