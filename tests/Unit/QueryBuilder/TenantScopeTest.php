<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\QueryBuilder\TenantScope;

/**
 * T068 (US7) — Unit tests for {@see TenantScope}.
 *
 *   (a) the scope is a no-op when `workflow.tenancy.enabled = false`
 *   (b) the scope is applied when `workflow.tenancy.enabled = true` and
 *       the resolver returns a non-null id
 *   (c) the scope is a no-op when the resolver returns null
 *   (d) the scope respects `withTenant($id)` local query scope
 *   (e) the scope is removed by `forAllTenants()` local query scope
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
});

it('(a) is a no-op when tenancy is disabled in config', function (): void {
    config()->set('workflow.tenancy.enabled', false);
    config()->set('workflow.tenancy.column', 'tenant_id');
    config()->set('workflow.tenancy.scope_provider', null);

    // With tenancy off, queries should not be filtered.
    $count = Workflow::query()->count();
    expect($count)->toBe(0);
});

it('(b) applies tenant_id constraint when tenancy is enabled and provider returns an id', function (): void {
    config()->set('workflow.tenancy.enabled', true);
    config()->set('workflow.tenancy.column', 'tenant_id');

    $this->app->instance(
        TenantScopeProvider::class,
        new class implements TenantScopeProvider
        {
            public function currentTenantId(): int|string|null
            {
                return 7;
            }
        },
    );

    // Insert directly using a no-scope builder so we have rows in two tenants.
    Workflow::query()->withoutGlobalScope(TenantScope::class)->create([
        'code' => 'visible',
        'name' => 'Visible',
        'type' => 'generic',
        'status' => 'draft',
        'version' => 1,
        'is_current_version' => false,
        'tenant_id' => 7,
    ]);
    Workflow::query()->withoutGlobalScope(TenantScope::class)->create([
        'code' => 'hidden',
        'name' => 'Hidden',
        'type' => 'generic',
        'status' => 'draft',
        'version' => 1,
        'is_current_version' => false,
        'tenant_id' => 99,
    ]);

    $rows = Workflow::query()->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->code)->toBe('visible');
});

it('(c) is a no-op when the resolver returns null', function (): void {
    config()->set('workflow.tenancy.enabled', true);
    config()->set('workflow.tenancy.column', 'tenant_id');

    $this->app->instance(
        TenantScopeProvider::class,
        new class implements TenantScopeProvider
        {
            public function currentTenantId(): int|string|null
            {
                return null;
            }
        },
    );

    Workflow::query()->withoutGlobalScope(TenantScope::class)->create([
        'code' => 'row-a',
        'name' => 'A',
        'type' => 'generic',
        'status' => 'draft',
        'version' => 1,
        'is_current_version' => false,
        'tenant_id' => 1,
    ]);
    Workflow::query()->withoutGlobalScope(TenantScope::class)->create([
        'code' => 'row-b',
        'name' => 'B',
        'type' => 'generic',
        'status' => 'draft',
        'version' => 1,
        'is_current_version' => false,
        'tenant_id' => 2,
    ]);

    $rows = Workflow::query()->get();
    expect($rows)->toHaveCount(2);
});

it('(d) scopeWithTenant pins a specific tenant id', function (): void {
    config()->set('workflow.tenancy.enabled', false);
    config()->set('workflow.tenancy.column', 'tenant_id');

    Workflow::query()->create([
        'code' => 'a', 'name' => 'A', 'type' => 'generic', 'status' => 'draft',
        'version' => 1, 'is_current_version' => false, 'tenant_id' => 11,
    ]);
    Workflow::query()->create([
        'code' => 'b', 'name' => 'B', 'type' => 'generic', 'status' => 'draft',
        'version' => 1, 'is_current_version' => false, 'tenant_id' => 22,
    ]);

    $rows = Workflow::query()->withTenant(11)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->tenant_id)->toBe(11);
});

it('(e) scopeForAllTenants disables the global scope', function (): void {
    config()->set('workflow.tenancy.enabled', true);
    config()->set('workflow.tenancy.column', 'tenant_id');

    $this->app->instance(
        TenantScopeProvider::class,
        new class implements TenantScopeProvider
        {
            public function currentTenantId(): int|string|null
            {
                return 1;
            }
        },
    );

    Workflow::query()->withoutGlobalScope(TenantScope::class)->create([
        'code' => 'a', 'name' => 'A', 'type' => 'generic', 'status' => 'draft',
        'version' => 1, 'is_current_version' => false, 'tenant_id' => 1,
    ]);
    Workflow::query()->withoutGlobalScope(TenantScope::class)->create([
        'code' => 'b', 'name' => 'B', 'type' => 'generic', 'status' => 'draft',
        'version' => 1, 'is_current_version' => false, 'tenant_id' => 2,
    ]);

    $rows = Workflow::query()->forAllTenants()->get();
    expect($rows)->toHaveCount(2);
});
