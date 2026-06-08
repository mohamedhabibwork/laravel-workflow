# Defining Workflows

You can define workflows in three ways:

- structured arrays through `WorkflowEngine::define()`
- direct Eloquent rows
- PHP attributes compiled with `workflow:compile-attributes`

The runtime engine always reads database rows.

## Define With `WorkflowEngine::define()`

```php
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;

/** @var WorkflowEngine $engine */
$engine = app(WorkflowEngine::class);

$workflow = $engine->define('order-approval', [
    'name' => 'Order Approval',
    'type' => 'approval',
    'subject_type' => App\Models\Order::class,
    'steps' => [
        ['key' => 'submitted', 'name' => 'Submitted', 'type' => 'start', 'position' => 1],
        [
            'key' => 'review',
            'name' => 'Manager Review',
            'type' => 'approval',
            'position' => 2,
            'authorization_mode' => 'roles',
            'match_mode' => 'any',
        ],
        ['key' => 'approved', 'name' => 'Approved', 'type' => 'end', 'position' => 3],
    ],
    'transitions' => [
        ['from' => '__start__', 'to' => 'review'],
        ['from' => 'review', 'to' => 'approved'],
    ],
]);

$active = $engine->activate($workflow);
```

`define()` creates a draft. `activate()` validates that the workflow has exactly one start step and at least one end step.

## Define With Eloquent Rows

Direct Eloquent authoring is useful in seeders, admin tools, or migration-like setup scripts.

```php
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Models\Workflow;

$workflow = Workflow::query()->create([
    'code' => 'order-approval',
    'name' => 'Order Approval',
    'type' => WorkflowType::Approval,
    'subject_type' => App\Models\Order::class,
    'status' => WorkflowStatus::Draft,
    'version' => 1,
]);
```

Then create `WorkflowStep`, `WorkflowStepAction`, `WorkflowStepAssignee`, `WorkflowCondition`, and `WorkflowTransition` rows.

## Activation Rules

Activation fails unless:

- the workflow is `draft`
- exactly one step has `type = start`
- at least one step has `type = end`

Activation sets the selected workflow to `active` and `is_current_version = true`. Previous current versions for the same tenant/code are flipped to `is_current_version = false`.

## Versioning

```php
$v2 = $engine->createNewVersion($activeWorkflow, [
    'name' => 'Order Approval v2',
]);
```

The new version is a draft clone of the workflow graph. Existing instances stay pinned to their original version.

## Start A Workflow

```php
$instance = $engine->start($active, $order, ['requester_role' => 'sales'], auth()->user());
```

If `subject_type` is set on the workflow, the subject model must match it.

