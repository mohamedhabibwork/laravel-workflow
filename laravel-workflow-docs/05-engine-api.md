# Workflow Engine API

The public service is `HFlow\LaravelWorkflow\Contracts\WorkflowEngine`.

```php
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;

public function __construct(private readonly WorkflowEngine $workflow) {}
```

## Definition Methods

### `define(string $key, array $definition): Workflow`

Creates a draft workflow from a structured array.

```php
$draft = $workflow->define('order-approval', $definition);
```

### `activate(Workflow|string $workflow): Workflow`

Activates a draft workflow after graph validation.

```php
$active = $workflow->activate($draft);
```

### `versions(Workflow|string $workflow): Collection`

Returns all versions for a workflow code, newest first.

```php
$versions = $workflow->versions('order-approval');
```

### `createNewVersion(Workflow $workflow, array $overrides = []): Workflow`

Deep-clones a workflow definition into a new draft version.

```php
$draftV2 = $workflow->createNewVersion($active, ['name' => 'Order Approval v2']);
```

## Runtime Methods

### `start(Workflow|string $workflow, Model $subject, array $context = [], mixed $initiator = null): WorkflowInstance`

Starts an instance and enters the start step.

```php
$instance = $workflow->start($active, $order, ['channel' => 'web'], auth()->user());
```

Throws when the workflow is not active or the subject does not match `subject_type`.

### `currentStep(WorkflowInstance $instance): WorkflowStepInstance|Collection`

Returns the active step instance. If parallel branches exist, returns a collection.

```php
$current = $workflow->currentStep($instance);
```

### `availableActions(WorkflowInstance $instance, mixed $user = null): ActionSet`

Returns deterministic, eligibility-filtered actions.

```php
$actions = $workflow->availableActions($instance, auth()->user());
```

### `perform(WorkflowInstance $instance, string $actionCode, mixed $user = null, ?array $payload = null): WorkflowInstance`

Performs an action and advances the workflow.

```php
$instance = $workflow->perform($instance, 'approve', auth()->user(), [
    'comment' => 'Approved after review.',
    'metadata' => ['source' => 'admin'],
]);
```

The engine re-validates eligibility, availability, and comment requirements before mutating state.

## Control Methods

### `skip(WorkflowInstance $instance, mixed $user = null, ?string $comment = null): WorkflowInstance`

Skips a skippable current step and appends history.

### `return(WorkflowInstance $instance, WorkflowStep|string|null $targetStep = null, mixed $user = null, ?string $comment = null): WorkflowInstance`

Returns to an earlier step by creating a new active step instance.

### `retry(WorkflowInstance $instance, mixed $user = null, ?string $comment = null): WorkflowInstance`

Re-enters the most recent failed step on a failed instance.

### `hold(WorkflowInstance $instance, mixed $user = null, ?string $comment = null): WorkflowInstance`

Moves an in-progress instance to `on_hold`.

### `resume(WorkflowInstance $instance, mixed $user = null): WorkflowInstance`

Resumes an `on_hold` instance.

### `cancel(WorkflowInstance $instance, mixed $user = null, ?string $comment = null): WorkflowInstance`

Cancels a non-terminal instance and closes active step instances.

## History Method

### `history(WorkflowInstance $instance, ?int $limit = null, ?string $event = null): Collection`

Reads the activity feed from append-only history rows.

```php
$all = $workflow->history($instance);
$recentErrors = $workflow->history($instance, limit: 10, event: 'error');
```

