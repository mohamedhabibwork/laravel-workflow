<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\QueryBuilder;

use HFlow\LaravelWorkflow\Concerns\AppliesTenantScope;
use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Eloquent global scope that, when `config('workflow.tenancy.enabled') = true`
 * AND the {@see TenantScopeProvider} returns a non-null tenant id, applies
 * `where tenant_id = $tenantId` to every query on a tenant-aware workflow
 * model (Workflow, WorkflowStep, WorkflowInstance, WorkflowHistory).
 *
 * When the host does not enable tenancy, the scope is a no-op.
 * When the host enables tenancy but the resolver returns null, the scope is
 * also a no-op (host's authorization layer is responsible for safety).
 *
 * Hosts that need to query across tenants (admin UIs, cron sweeps) can call
 * `Model::withoutGlobalScope(TenantScope::class)` or use the `withTenant($id)`
 * / `forAllTenants()` local scopes provided by {@see AppliesTenantScope}.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! (bool) config('workflow.tenancy.enabled', false)) {
            return;
        }

        $provider = $this->resolveProvider();
        if ($provider === null) {
            return;
        }

        $tenantId = $provider->currentTenantId();
        if ($tenantId === null) {
            return;
        }

        $column = (string) config('workflow.tenancy.column', 'tenant_id');

        $builder->where($model->getTable().'.'.$column, '=', $tenantId);
    }

    /**
     * Resolve the {@see TenantScopeProvider} from the application container
     * (or the bound singleton on the model itself when running in a
     * test isolation context).
     */
    private function resolveProvider(): ?TenantScopeProvider
    {
        if (function_exists('app') && app()->bound(TenantScopeProvider::class)) {
            $resolved = app(TenantScopeProvider::class);

            return $resolved instanceof TenantScopeProvider ? $resolved : null;
        }

        return null;
    }
}
