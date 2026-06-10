# Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-workflow-config"
```

## Table Prefix

```php
'table_prefix' => Env::get('WORKFLOW_TABLE_PREFIX', 'workflow_'),
```

By default, tables use the `workflow_` prefix. For example:

- `workflow_workflows`
- `workflow_workflow_steps`
- `workflow_workflow_instances`
- `workflow_workflow_histories`

Set a custom prefix in `.env`:

```dotenv
WORKFLOW_TABLE_PREFIX=wf_
```

## Multi-Tenancy

```php
'multi_tenancy' => [
    'enabled' => Env::get('WORKFLOW_TENANCY_ENABLED', false),
    'column' => 'tenant_id',
    'current_tenant_id' => null,
    'resolver' => null,
],
```

Enable tenant filtering:

```dotenv
WORKFLOW_TENANCY_ENABLED=true
```

Set the current tenant using config:

```php
config(['workflow.multi_tenancy.current_tenant_id' => tenant('id')]);
```

Or set a resolver callback in a service provider:

```php
config([
    'workflow.multi_tenancy.resolver' => fn () => tenant('id'),
]);
```

See [Multi-Tenancy](multi-tenancy.md) for more detail.

## Attribute Workflows

```php
'attributes' => [
    'workflows' => [
        App\Workflows\OrderApprovalWorkflow::class,
    ],
],
```

Run `php artisan workflow:sync-attributes` to sync configured classes.

## Activities

```php
'activities' => [
    'retry_delay_seconds' => Env::get('WORKFLOW_ACTIVITY_RETRY_DELAY_SECONDS', 5),
],
```

This delay is used when an activity fails but still has remaining attempts.

## Class Overrides

```php
'classes' => [
    'api' => LaravelWorkflow::class,
    'workflow_builder' => WorkflowBuilder::class,
    'workflow_engine' => WorkflowEngine::class,
    'workflow_service' => WorkflowService::class,
    'attribute_workflow_registrar' => AttributeWorkflowRegistrar::class,
    'activity_service' => ActivityService::class,
    'action_resolver' => ActionResolver::class,
    'condition_evaluator' => ConditionEvaluator::class,
],
```

Override classes only with subclasses compatible with the package class. Invalid overrides fall back to the default.
