<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Contracts;

/**
 * Host-supplied provider of the current tenant id.
 *
 * Registered in `config/workflow.php` as `tenancy.scope_provider` when
 * multi-tenancy is enabled. The engine resolves it on every query that
 * touches a tenancy-aware table.
 *
 * Contract:
 *  - Resolver MUST return int|string|null — the tenant_id to scope by.
 *    Return null to mean "no tenant scope" (engine behaves as single-tenant)
 *  - Resolver MUST NOT mutate any global state
 *  - Resolver MUST be cheap and side-effect-free (called frequently)
 *  - Resolver MAY return null even when tenancy is enabled; the host's
 *    authorization layer is responsible for safety in that case
 *
 * @see /specs/002-laravel-workflow-engine/contracts/host-contracts.md#6-tenantscopeprovider
 */
interface TenantScopeProvider
{
    public function currentTenantId(): int|string|null;
}
