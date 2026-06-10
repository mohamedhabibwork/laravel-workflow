# Customization and Overrides

Laravel Workflow is designed so applications can customize behavior without editing package source. Override package classes in `config/workflow.php` or bind your own implementations in Laravel's container.

## Configurable Classes

The package exposes class override points:

```php
'classes' => [
    'api' => \HFlow\LaravelWorkflow\LaravelWorkflow::class,
    'workflow_builder' => \HFlow\LaravelWorkflow\Builders\WorkflowBuilder::class,
    'workflow_engine' => \HFlow\LaravelWorkflow\Services\WorkflowEngine::class,
    'workflow_service' => \HFlow\LaravelWorkflow\Services\WorkflowService::class,
    'action_resolver' => \HFlow\LaravelWorkflow\Services\ActionResolver::class,
    'condition_evaluator' => \HFlow\LaravelWorkflow\Services\ConditionEvaluator::class,
],
```

Custom classes should extend the package class they replace. If an invalid class is configured, the package falls back to the default.

## Override the Workflow Engine

```php
namespace App\Workflows;

use HFlow\LaravelWorkflow\Services\WorkflowEngine;

class AppWorkflowEngine extends WorkflowEngine
{
    // Override or add application behavior here.
}
```

Configure it:

```php
// config/workflow.php
'classes' => [
    'workflow_engine' => \App\Workflows\AppWorkflowEngine::class,
],
```

Resolve it:

```php
$engine = app(\HFlow\LaravelWorkflow\Services\WorkflowEngine::class);
```

## Override the Model Builder

```php
namespace App\Workflows;

use HFlow\LaravelWorkflow\Builders\WorkflowBuilder;

class AppWorkflowBuilder extends WorkflowBuilder
{
    public function approveWithComment(string $comment): self
    {
        return $this->performAction('approve', auth()->user(), [
            'comment' => $comment,
        ]);
    }
}
```

Configure it:

```php
'classes' => [
    'workflow_builder' => \App\Workflows\AppWorkflowBuilder::class,
],
```

Use it from a model:

```php
$order->workflow()->approveWithComment('Approved.');
```

## Override the Public API Class

```php
namespace App\Workflows;

use HFlow\LaravelWorkflow\LaravelWorkflow;

class AppLaravelWorkflow extends LaravelWorkflow
{
    public function startOrderWorkflow($order)
    {
        return $this->start('order-approval', $order);
    }
}
```

Configure it:

```php
'classes' => [
    'api' => \App\Workflows\AppLaravelWorkflow::class,
],
```

Use it through the container or facade target:

```php
app(\HFlow\LaravelWorkflow\LaravelWorkflow::class)->startOrderWorkflow($order);
```

## Runtime Engine Replacement

You can also replace the engine at runtime:

```php
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;

LaravelWorkflow::useEngine($customEngine);

$order->useWorkflowEngine($customEngine);
$order->workflow()->useEngine($customEngine);
```

Runtime replacement is useful in tests or advanced per-request scenarios. Config overrides are better for application-wide behavior.

## Extension Contracts

For most business-specific behavior, prefer extension contracts over service overrides:

- `CustomAuthorizer`
- `CustomConditionEvaluator`
- `StepHandler`
- `ActionHandler`
- `AssigneeResolver`

See [Extension Contracts](extension-contracts.md).
