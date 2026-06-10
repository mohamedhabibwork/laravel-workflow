<?php

namespace HFlow\LaravelWorkflow\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply tenant filtering directly or through the closest tenant-aware relation.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! config('workflow.multi_tenancy.enabled') || empty($tenantId = $this->getTenantId())) {
            return;
        }

        $column = config('workflow.multi_tenancy.column', 'tenant_id');

        if ($this->hasFillableColumn($model, $column)) {
            $builder->where($model->qualifyColumn($column), $tenantId);

            return;
        }

        $tenantRelation = $this->tenantRelationFor($model);

        if ($tenantRelation !== null) {
            $builder->whereHas($tenantRelation, function (Builder $query) use ($column, $tenantId) {
                $query->where($column, $tenantId);
            });
        }
    }

    /**
     * Determine whether the model can be filtered by tenant column directly.
     */
    protected function hasFillableColumn(Model $model, string $column): bool
    {
        return in_array($column, $model->getFillable(), true);
    }

    /**
     * Resolve the relationship path used to inherit tenant filtering.
     */
    protected function tenantRelationFor(Model $model): ?string
    {
        return match (true) {
            method_exists($model, 'workflow') => 'workflow',
            method_exists($model, 'step') => 'step.workflow',
            method_exists($model, 'workflowInstance') => 'workflowInstance',
            method_exists($model, 'stepInstance') => 'stepInstance.workflowInstance',
            default => null,
        };
    }

    /**
     * Resolve the current tenant ID from config, a resolver callback, or the auth user.
     */
    protected function getTenantId(): mixed
    {
        $resolver = config('workflow.multi_tenancy.resolver');

        if (is_callable($resolver)) {
            return $resolver();
        }

        $configuredTenantId = config('workflow.multi_tenancy.current_tenant_id')
            ?? config('workflow.current_tenant_id');

        if ($configuredTenantId !== null) {
            return $configuredTenantId;
        }

        $user = auth()->user();

        if ($user instanceof Model) {
            return $user->getAttribute('tenant_id');
        }

        return null;
    }
}
