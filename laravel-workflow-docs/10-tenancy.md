# Tenancy

Tenancy is optional. When enabled, workflow definition and runtime queries are scoped by the current tenant id returned by the host.

## Enable

```php
'tenancy' => [
    'enabled' => true,
    'column' => 'tenant_id',
    'scope_provider' => App\Workflow\CurrentTenant::class,
],
```

## Provider

```php
namespace App\Workflow;

use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;

final class CurrentTenant implements TenantScopeProvider
{
    public function currentTenantId(): int|string|null
    {
        return tenant()?->getKey();
    }
}
```

## Behavior

- Queries on tenancy-aware models receive a global tenant scope.
- New workflow rows are stamped with the current tenant id when available.
- The same workflow `code` may exist in multiple tenants.
- Duplicate workflow `code` values are rejected inside the same tenant.
- If the provider returns `null`, the scope is a no-op. Host authorization remains responsible for safety in that case.

## Cross-Tenant Admin Commands

Some commands support `--all` to bypass tenant scope:

```bash
php artisan workflow:list --all
php artisan workflow:diagnose --all
```

Use this only in trusted admin contexts.

