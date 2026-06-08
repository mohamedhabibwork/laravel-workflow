<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Concerns;

use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;
use HFlow\LaravelWorkflow\QueryBuilder\TenantScope;

/**
 * Trait that registers the {@see TenantScope} global scope on a tenant-aware
 * workflow model (those that have a `tenant_id` column).
 *
 * Applied to: Workflow, WorkflowStep, WorkflowInstance, WorkflowHistory.
 *
 * Models that do NOT have a `tenant_id` column (WorkflowStepAssignee,
 * WorkflowStepAction, WorkflowCondition, WorkflowTransition,
 * WorkflowStepInstance, WorkflowAssignment) are transitively scoped by
 * their parent — when the parent's tenant scope is active, FK joins
 * automatically restrict the child set.
 *
 * Provides three local scopes for hosts that need to escape the scope:
 *  - `withTenant(int|string $id)` — set the scope's tenant to a specific id
 *  - `forAllTenants()` — disable the scope entirely (admin / cron)
 *  - `forCurrentTenant()` — re-apply the scope after a `forAllTenants()`
 */
trait AppliesTenantScope
{
    /**
     * Boot the trait. The `booted` hook is called once per model class
     * (not per instance), so the global scope is registered lazily and
     * reflects the current `config('workflow.tenancy.enabled')` flag.
     */
    public static function bootAppliesTenantScope(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Resolve the current tenant id (from the provider) or null.
     * Exposed as a static helper for the engine's write paths.
     */
    public static function currentTenantId(): int|string|null
    {
        if (! (bool) config('workflow.tenancy.enabled', false)) {
            return null;
        }

        if (! function_exists('app') || ! app()->bound(TenantScopeProvider::class)) {
            return null;
        }

        $provider = app(TenantScopeProvider::class);

        return $provider instanceof TenantScopeProvider ? $provider->currentTenantId() : null;
    }

    /**
     * Local query scope: pin the tenant to a specific id for this query.
     * Equivalent to the global scope being active, but with a known id.
     *
     * Usage: `Workflow::query()->withTenant(42)->get();`
     *
     * @param  Builder<self>  $builder
     */
    public function scopeWithTenant(\Illuminate\Database\Eloquent\Builder $builder, int|string $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        $column = (string) config('workflow.tenancy.column', 'tenant_id');

        return $builder->where($this->getTable().'.'.$column, '=', $tenantId);
    }

    /**
     * Local query scope: bypass the global TenantScope for this query.
     * Use for admin tools, cron sweeps, and cross-tenant reports.
     *
     * @param  Builder<self>  $builder
     */
    public function scopeForAllTenants(\Illuminate\Database\Eloquent\Builder $builder): \Illuminate\Database\Eloquent\Builder
    {
        return $builder->withoutGlobalScope(TenantScope::class);
    }
}
