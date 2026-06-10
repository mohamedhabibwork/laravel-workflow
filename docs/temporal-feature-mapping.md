# Temporal Feature Mapping

This package is not a Temporal SDK and does not require a Temporal server. It implements comparable workflow concepts with Laravel, Eloquent, Artisan commands, and the Laravel service container.

## Workflow Basics

| Concept | Laravel Workflow |
| --- | --- |
| Workflow definition | `Workflow`, `WorkflowStep`, `WorkflowTransition`, `WorkflowStepAction` |
| Workflow execution | `WorkflowInstance` |
| Workflow run | `run_id` on `WorkflowInstance` |
| Workflow ID | `workflow_identity` |
| Event history | `WorkflowHistory` |
| Worker task queue | `task_queue` |

## Child Workflows

Use `startChild($parent, $workflow, $subject, $context)` or `LaravelWorkflow::startChild(...)`.

Child runs store:

- `parent_instance_id`
- `parent_workflow_instance_id` in context
- `child_started` history on the parent

## Continue-As-New

Use `continueAsNew($instance, $context)`.

The next run:

- gets a new `run_id`
- keeps `workflow_identity`
- keeps `first_execution_run_id`
- starts on the same workflow definition and subject

## Cancellation And Termination

Use:

```php
LaravelWorkflow::cancel($instance, 'reason');
LaravelWorkflow::terminate($instance, 'reason');
```

Cancellation closes active steps as cancelled. Termination force-closes the run and cancels pending timers.

## Timeouts

Workflow start options:

- `run_timeout_seconds`
- `execution_timeout_seconds`

Activity options:

- `schedule_to_close_timeout_seconds`
- `start_to_close_timeout_seconds`

Process timeouts with:

```bash
php artisan workflow:run-due
```

## Messages

| Temporal concept | Laravel Workflow |
| --- | --- |
| Signal | `signal()` |
| Update | `update()` plus optional validator |
| Query | `query()` |

Handlers are container-resolved classes configured on workflow `config` or via PHP attributes.

## Schedules And Timers

Delayed workflow start:

```php
LaravelWorkflow::startWithOptions('code', $subject, [], null, [
    'start_delay_seconds' => 300,
]);
```

Timer:

```php
LaravelWorkflow::scheduleTimer($instance, 'name', now()->addMinutes(10));
```

Process due work with `workflow:run-due` or `workflow:work`.

## Side Effects

Put non-deterministic or external side effects in:

- action handlers
- automated step handlers
- activity handlers
- signal/update/timer handlers

Workflow routing should remain based on persisted state, conditions, and explicit transitions.

## Versioning

Workflow definitions use `code`, `version`, and `is_current_version`.

Use `WorkflowService::createNewVersion($workflow)` to clone a draft version. Existing instances remain bound to the workflow row and version they started with.

## Activities

| Temporal concept | Laravel Workflow |
| --- | --- |
| Activity definition | `ActivityHandler` |
| Activity task | `WorkflowActivity` |
| Activity execution | `ActivityService::runDue()` or `workflow:work` |
| Async completion | `ActivityResult::async()` and `completeAsyncActivity()` |
| Activity timeout | activity timeout columns and `processTimeouts()` |
| Worker process | `php artisan workflow:work` |

