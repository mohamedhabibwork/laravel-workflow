# Configuration

Publish the config file with:

```bash
php artisan vendor:publish --tag=laravel-workflow-config
```

## Keys

| Key | Default | Purpose |
|---|---:|---|
| `table_prefix` | `workflow_` | Prefix used by the migration and models. |
| `database_connection` | `null` | Optional Laravel connection name for workflow queries. `null` uses the host default. |
| `history_retention_days` | `null` | Reserved for host cleanup policies. `null` means keep forever. |
| `tenancy.enabled` | `false` | Enables global tenant scoping on tenancy-aware workflow models. |
| `tenancy.column` | `tenant_id` | Column name used for tenant scoping. |
| `tenancy.scope_provider` | `null` | FQCN implementing `TenantScopeProvider`. |
| `custom_contracts.authorizer` | `null` | Default host implementation for `CustomAuthorizer`. |
| `custom_contracts.condition_evaluator` | `null` | Default host implementation for `CustomConditionEvaluator`. |
| `custom_contracts.action_handler` | `null` | Default host implementation for `CustomActionHandler`. |
| `custom_contracts.step_handler` | `null` | Default host implementation for `CustomStepHandler`. |
| `custom_contracts.resolver` | `null` | Default host implementation for `CustomResolver`. |
| `events.fire_laravel_events` | `true` | Whether the package dispatches typed Laravel events around history recording. |
| `history.on_dispatch_failure` | `skip` | `skip` ignores listener errors, `throw` rethrows them. |
| `automation.max_retry_attempts` | `3` | Host-facing retry tuning for automation. |
| `automation.retry_backoff_seconds` | `[10, 60, 300]` | Suggested backoff intervals for hosts that schedule retries. |
| `automation.max_chain_depth` | `50` | Stops accidental infinite automation chains. |
| `attribute_paths` | `['app/Workflows']` | Directories scanned by `workflow:compile-attributes`. |
| `compile_on_boot` | `false` | When true, discovers/compiles attribute workflows during package boot. |

## Tenancy Example

```php
use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;

final class CurrentTenant implements TenantScopeProvider
{
    public function currentTenantId(): int|string|null
    {
        return tenant()?->getKey();
    }
}
```

```php
'tenancy' => [
    'enabled' => true,
    'column' => 'tenant_id',
    'scope_provider' => App\Workflow\CurrentTenant::class,
],
```

## Custom Contract Defaults

The `custom_contracts` keys bind default implementations in the service container. Row-level FQCNs, such as `workflow_steps.custom_authorizer` or `workflow_step_actions.handler`, can still override behavior for a specific step/action.

## Attribute Compile Paths

```php
'attribute_paths' => [
    'app/Workflows',
    'modules/Billing/Workflows',
],
```

Each path is relative to the host app base path. You can also compile a single file:

```bash
php artisan workflow:compile-attributes --path=app/Workflows/OrderApprovalWorkflow.php
```

