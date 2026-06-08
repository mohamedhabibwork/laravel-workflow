# Laravel Workflow

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mohamedhabibwork/laravel-workflow.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-workflow)
[![Total Downloads](https://img.shields.io/packagist/dt/mohamedhabibwork/laravel-workflow.svg?style=flat-square)](https://packagist.org/packages/mohamedhabibwork/laravel-workflow)

`mohamedhabibwork/laravel-workflow` is a reusable Laravel package for versioned workflow definitions, runtime workflow instances, user actions, automation chains, optional tenancy, and append-only activity history. It is host-agnostic: workflows can be attached to any Eloquent model, the package never owns the host `users` table, and all type/status values are backed by PHP enums rather than database enum or lookup tables.

## Installation

```bash
composer require mohamedhabibwork/laravel-workflow
php artisan vendor:publish --tag=laravel-workflow-migrations
php artisan migrate
php artisan vendor:publish --tag=laravel-workflow-config
```

The migration creates the definition tables (`workflow_workflows`, `workflow_steps`, `workflow_step_assignees`, `workflow_step_actions`, `workflow_conditions`, `workflow_transitions`) and runtime tables (`workflow_instances`, `workflow_step_instances`, `workflow_assignments`, `workflow_histories`) using the configured table prefix.

## Configuration

```php
return [
    'table_prefix' => 'workflow_',
    'database_connection' => null,
    'history_retention_days' => null,

    'tenancy' => [
        'enabled' => false,
        'column' => 'tenant_id',
        'scope_provider' => null,
    ],

    'custom_contracts' => [
        'authorizer' => null,
        'condition_evaluator' => null,
        'action_handler' => null,
        'step_handler' => null,
        'resolver' => null,
    ],

    'events' => [
        'fire_laravel_events' => true,
    ],

    'history' => [
        'on_dispatch_failure' => 'skip',
    ],

    'automation' => [
        'max_retry_attempts' => 3,
        'retry_backoff_seconds' => [10, 60, 300],
        'max_chain_depth' => 50,
    ],

    'attribute_paths' => ['app/Workflows'],
    'compile_on_boot' => false,
];
```

## Defining Workflows

You can define workflows by writing rows directly through Eloquent, by using `WorkflowEngine::define()`, or by compiling PHP attributes into the same tables.

```php
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;

$workflow = app(WorkflowEngine::class)->define('order-approval', [
    'name' => 'Order Approval',
    'type' => 'approval',
    'subject_type' => App\Models\Order::class,
    'steps' => [
        ['key' => 'submitted', 'name' => 'Submitted', 'type' => 'start', 'position' => 1],
        ['key' => 'review', 'name' => 'Review', 'type' => 'approval', 'position' => 2],
        ['key' => 'approved', 'name' => 'Approved', 'type' => 'end', 'position' => 3],
    ],
    'transitions' => [
        ['from' => '__start__', 'to' => 'review'],
        ['from' => 'review', 'to' => 'approved'],
    ],
]);

$active = app(WorkflowEngine::class)->activate($workflow);
```

Attribute authoring is available for teams that prefer typed, IDE-visible definitions:

```php
use HFlow\LaravelWorkflow\Attributes\Action;
use HFlow\LaravelWorkflow\Attributes\AsWorkflow;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Transition;

#[AsWorkflow(code: 'order-approval', name: 'Order Approval', subject: App\Models\Order::class, type: 'approval')]
#[Transition(from: 'submitted', to: 'review', on: 'submit')]
#[Transition(from: 'review', to: 'approved', on: 'approve')]
final class OrderApprovalWorkflow
{
    #[Step(code: 'submitted', name: 'Submitted', type: 'start', position: 1)]
    #[Action(code: 'submit', name: 'Submit', type: 'submit', targetStep: 'review')]
    public function submitted(): void {}

    #[Step(code: 'review', name: 'Review', type: 'approval', position: 2)]
    #[Action(code: 'approve', name: 'Approve', type: 'approve', targetStep: 'approved')]
    public function review(): void {}

    #[Step(code: 'approved', name: 'Approved', type: 'end', position: 3)]
    public function approved(): void {}
}
```

Compile attributes with:

```bash
php artisan workflow:compile-attributes --path=app/Workflows
php artisan workflow:compile-attributes --dry-run
```

## Public API

The single host-facing service is `HFlow\LaravelWorkflow\Contracts\WorkflowEngine`. Type-hint it in constructors or resolve it from the container.

```php
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;

public function __construct(private readonly WorkflowEngine $workflow) {}
```

Definition methods:

```php
$draft = $workflow->define('order-approval', $definition);
$active = $workflow->activate($draft);
$versions = $workflow->versions('order-approval');
$nextDraft = $workflow->createNewVersion($active, ['name' => 'Order Approval v2']);
```

Runtime methods:

```php
$instance = $workflow->start($active, $order, ['source' => 'checkout'], auth()->user());
$current = $workflow->currentStep($instance);
$actions = $workflow->availableActions($instance, auth()->user());
$instance = $workflow->perform($instance, 'approve', auth()->user(), ['comment' => 'Looks good.']);
```

Control methods:

```php
$instance = $workflow->skip($instance, auth()->user(), 'Not required.');
$instance = $workflow->return($instance, 'review', auth()->user(), 'Needs another pass.');
$instance = $workflow->retry($failedInstance, auth()->user(), 'Retry automation.');
$instance = $workflow->hold($instance, auth()->user(), 'Waiting for customer details.');
$instance = $workflow->resume($instance, auth()->user());
$instance = $workflow->cancel($instance, auth()->user(), 'Cancelled by requester.');
```

History methods:

```php
$events = $workflow->history($instance);
$recentErrors = $workflow->history($instance, limit: 10, event: 'error');
```

Every state-changing method writes append-only history rows. History rows have `created_at` only, are not soft-deleted, and are protected by tests and architecture rules.

## Extending With Host Contracts

Hosts can plug in custom behavior by implementing these interfaces and storing the FQCN on the relevant workflow row or config key:

- `CustomAuthorizer`: decide whether a user may act on a step.
- `CustomConditionEvaluator`: evaluate host-specific condition logic.
- `CustomActionHandler`: run side effects for an action.
- `CustomStepHandler`: run automated step logic.
- `CustomResolver`: resolve host-specific values such as dynamic assignees.
- `TenantScopeProvider`: return the current tenant id when tenancy is enabled.

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

## Artisan Commands

```bash
php artisan workflow:list
php artisan workflow:status {instance?}
php artisan workflow:history {instance} --limit=20
php artisan workflow:diagnose {workflow?}
php artisan workflow:compile-attributes --path=app/Workflows
```

## Testing And Quality

```bash
composer test
composer analyse
vendor/bin/pint --test
```

The suite uses Pest, Pest Arch, Orchestra Testbench, Larastan, and Laravel Pint. The CI matrix is defined in `.github/workflows/tests.yml`, with lint/static-analysis checks in `.github/workflows/lint.yml`.

## Documentation

Full package documentation lives in [`laravel-workflow-docs/`](./laravel-workflow-docs/README.md):

- [Installation](./laravel-workflow-docs/01-installation.md)
- [Configuration](./laravel-workflow-docs/02-configuration.md)
- [Workflow Engine API](./laravel-workflow-docs/05-engine-api.md)
- [PHP Attributes](./laravel-workflow-docs/06-php-attributes.md)
- [Artisan Commands](./laravel-workflow-docs/11-artisan-commands.md)
- [Troubleshooting](./laravel-workflow-docs/13-troubleshooting.md)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
