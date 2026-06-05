# Quickstart: Laravel Workflow Engine

**Feature**: 002-laravel-workflow-engine
**Date**: 2026-06-05
**Status**: Complete

A 5-minute tour of the package from the host application's point of view. Covers install, configure, define a workflow, start an instance, get current step, list available actions, perform one, and read history.

---

## 1. Install

```bash
# 1. Require the package in your Laravel 10–13 application.
composer require mohamedhabibwork/laravel-workflow

# 2. Publish & run the migration. This creates the 10 workflow tables.
php artisan vendor:publish --tag=laravel-workflow-migrations
php artisan migrate

# 3. (Optional) Publish the config file to customize the table prefix or enable tenancy.
php artisan vendor:publish --tag=laravel-workflow-config
```

After step 2, your database has these tables (with the `workflow_` prefix by default):

```
workflows
workflow_steps
workflow_step_assignees
workflow_step_actions
workflow_conditions
workflow_transitions
workflow_instances
workflow_step_instances
workflow_assignments
workflow_histories
```

No `users` table is created and no `lookup_*` tables are created. The package only stores foreign-key references to the host's `users` table.

---

## 2. Configure (optional)

Edit `config/workflow.php`:

```php
return [
    // Defaults shown; nothing is required.
    'table_prefix' => 'workflow_',

    'database_connection' => null, // null = host's default connection

    'tenant' => [
        'enabled' => false,         // set true to opt into multi-tenancy
        'column'  => 'tenant_id',
        'scope_resolver' => null,  // FQCN implementing TenantScopeProvider
    ],

    'history' => [
        'append_only' => true,     // locked; never change this
    ],

    'automation' => [
        'max_chain_depth' => 50,   // safety guard against infinite loops
    ],
];
```

If you set `'tenant.enabled' => true`, also implement the `TenantScopeProvider` contract so the engine can ask "what is the current tenant?":

```php
namespace App\Workflow\Tenancy;

use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;

final class CurrentTenantFromRequest implements TenantScopeProvider
{
    public function currentTenantId(): int|string|null
    {
        return request()->header('X-Tenant-Id') ?? session('current_tenant_id');
    }
}
```

Then in `config/workflow.php`:

```php
'tenant' => [
    'enabled' => true,
    'column'  => 'tenant_id',
    'scope_resolver' => \App\Workflow\Tenancy\CurrentTenantFromRequest::class,
],
```

---

## 3. Define a workflow

A workflow is a set of Eloquent rows. The cleanest way to define one for production is a seeder; for exploration, use a one-off script or `php artisan tinker`.

```php
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAssignee;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use HFlow\LaravelWorkflow\States\WorkflowType;
use HFlow\LaravelWorkflow\States\StepType;
use HFlow\LaravelWorkflow\States\AuthorizationMode;
use HFlow\LaravelWorkflow\States\MatchMode;
use HFlow\LaravelWorkflow\States\ActionType;
use HFlow\LaravelWorkflow\States\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\States\TransitionType;
use HFlow\LaravelWorkflow\States\AssigneeType;

// 1) Create the workflow.
$workflow = Workflow::create([
    'code'        => 'order-approval',
    'name'        => 'Order Approval',
    'description' => 'Two-step approval for high-value orders.',
    'type'        => WorkflowType::Approval,
    'subject_type' => \App\Models\Order::class, // optional; null = generic
    'status'      => \HFlow\LaravelWorkflow\States\WorkflowStatus::Draft,
]);

// 2) Create the steps.
$start = WorkflowStep::create([
    'workflow_id' => $workflow->id,
    'code'        => 'submitted',
    'name'        => 'Submitted',
    'type'        => StepType::Start,
    'position'    => 1,
    'authorization_mode' => AuthorizationMode::Public,
    'match_mode'  => MatchMode::Any,
]);

$managerReview = WorkflowStep::create([
    'workflow_id' => $workflow->id,
    'code'        => 'manager-review',
    'name'        => 'Manager Review',
    'type'        => StepType::Approval,
    'position'    => 2,
    'authorization_mode' => AuthorizationMode::Roles,
    'match_mode'  => MatchMode::Any,
    'is_skippable'   => false,
    'is_returnable'  => true,
    'sla_seconds'    => 24 * 60 * 60, // 24 hours
]);

$approved = WorkflowStep::create([
    'workflow_id' => $workflow->id,
    'code'        => 'approved',
    'name'        => 'Approved',
    'type'        => StepType::End,
    'position'    => 3,
    'authorization_mode' => AuthorizationMode::Public,
    'match_mode'  => MatchMode::Any,
]);

// 3) Wire the start step.
$workflow->update(['start_step_id' => $start->id]);

// 4) Add the assignees on the manager-review step.
WorkflowStepAssignee::create([
    'step_id'       => $managerReview->id,
    'assignee_type' => AssigneeType::Role,
    'assignee_value' => 'manager',
]);

// 5) Add the actions on the manager-review step.
$approveAction = WorkflowStepAction::create([
    'step_id'           => $managerReview->id,
    'code'              => 'approve',
    'name'              => 'Approve',
    'type'              => ActionType::Approve,
    'availability_mode' => ActionAvailabilityMode::General,
    'target_step_id'    => $approved->id,
    'requires_comment'  => false,
    'sort_order'        => 1,
]);

WorkflowStepAction::create([
    'step_id'           => $managerReview->id,
    'code'              => 'reject',
    'name'              => 'Reject',
    'type'              => ActionType::Reject,
    'availability_mode' => ActionAvailabilityMode::General,
    'target_step_id'    => $approved->id, // could route to a rejected end step
    'requires_comment'  => true,           // must include a comment
    'sort_order'        => 2,
]);

// 6) Add transitions (mostly optional; the engine falls back to position order).
WorkflowTransition::create([
    'workflow_id' => $workflow->id,
    'from_step_id' => $start->id,
    'to_step_id'   => $managerReview->id,
    'type'         => TransitionType::Forward,
    'priority'     => 0,
]);

// 7) Activate the workflow.
app(\HFlow\LaravelWorkflow\Contracts\WorkflowEngine::class)->activate($workflow);
```

After `activate()`, the workflow is `active` and can start instances.

---

## 4. Start an instance

```php
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use App\Models\Order;

$order  = Order::find(1);                        // any host model
$engine = app(WorkflowEngine::class);

$instance = $engine->start(
    workflow:  $workflow,                        // the Workflow model from above
    subject:  $order,                            // the host model record
    context:  ['requester_role' => 'sales'],     // optional runtime data
    initiator: auth()->user(),                   // optional; null = system
);

// $instance is now in `in_progress` on the start step.
$instance->status;                       // 'in_progress'
$instance->workflow_version;              // 1 (pinned)
$current = $engine->currentStep($instance);
$current->step->code;                     // 'submitted'
```

The engine has written three rows so far:

- `workflow_instances` row, `status = in_progress`
- `workflow_step_instances` row, `status = active`, `step_id` = start step
- `workflow_histories` row, `event = started`

---

## 5. Get available actions for a user

```php
$actions = $engine->availableActions($instance, auth()->user());

foreach ($actions as $action) {
    echo $action->code . ' — ' . $action->name . PHP_EOL;
    // 'approve — Approve'
    // 'reject — Reject'
}
```

If the user is not a manager, `$actions` is empty (the engine's eligibility check returns false for the `roles` mode and the user does not hold the `manager` role).

---

## 6. Perform an action

```php
$updated = $engine->perform(
    instance:  $instance,
    actionCode: 'approve',
    user:       auth()->user(),
    payload:    ['comment' => 'Looks good.'],
);

$updated->status;   // 'completed' (we routed directly to the end step)
$history = $engine->history($updated);
$history->count();  // 4+ (started, step_entered, step_completed, action_performed, step_entered, completed)
```

If the user is not eligible, or the action is not in the available set, or the action requires a comment and none was provided, the engine throws a typed exception (`NotEligibleException`, `ActionNotAvailableException`, `CommentRequiredException`). The instance state is **not** changed on any of these failures.

---

## 7. Skip and return

```php
// Skip the current step (only works if the step is_skippable and the guard passes).
$engine->skip($instance, auth()->user(), 'No manager available; auto-approving.');

// Return to a previous step.
$engine->return(
    instance:   $instance,
    targetStep: $managerReview,    // WorkflowStep model
    user:       auth()->user(),
    comment:    'Customer updated the amount; please re-review.',
);
```

Skip and return never delete or modify prior history. They always append new events.

---

## 8. Read the activity feed

```php
$events = $engine->history($instance, limit: 50);

foreach ($events as $event) {
    printf(
        "[%s] %s by %s on step %s → %s%s\n",
        $event->performed_at->toIso8601String(),
        $event->event->value,
        $event->actor_type === 'system' ? 'system' : ('user#' . $event->actor_id),
        $event->fromStep?->code ?? '∅',
        $event->toStep?->code ?? '∅',
        $event->comment ? ' (' . $event->comment . ')' : '',
    );
}
```

The feed is a chronological, append-only stream of events. The same row is never returned twice and never modified between calls.

---

## 9. Cancel / hold / resume / retry

```php
$engine->hold($instance, auth()->user(), 'Waiting for customer info.');
$engine->resume($instance, auth()->user());

$engine->cancel($instance, auth()->user(), 'Order cancelled by customer.');
// $instance->status is now 'cancelled'.

// On a failed instance (e.g. an automated handler threw):
$engine->retry($instance, auth()->user());
// $instance->status is back to 'in_progress' with a fresh active step instance.
```

---

## 10. Custom contracts

For any of the five host-supplied contracts (`CustomAuthorizer`, `CustomConditionEvaluator`, `CustomActionHandler`, `CustomStepHandler`, `CustomResolver`), write a class that implements the interface and store its FQCN on the corresponding row.

```php
// e.g. on a step that should only be eligible to the order's region manager:
$step = WorkflowStep::create([
    'workflow_id'       => $workflow->id,
    'code'              => 'region-approval',
    'name'              => 'Region Approval',
    'type'              => StepType::Approval,
    'position'          => 4,
    'authorization_mode'=> AuthorizationMode::Custom,
    'match_mode'        => MatchMode::Any,
    'custom_authorizer' => \App\Workflow\Authorizers\RegionManagerAuthorizer::class,
]);
```

See `contracts/host-contracts.md` for the full contract for each interface.

---

## 11. Tests

The package ships with a Pest 4 test suite. The host can also write their own tests against the engine API:

```php
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;

it('starts an instance on the order', function () {
    $workflow = Workflow::where('code', 'order-approval')->firstOrFail();
    $order    = Order::factory()->create();

    $instance = app(WorkflowEngine::class)->start($workflow, $order);

    expect($instance->status)->toBe(InstanceStatus::InProgress);
    expect($instance->workflowable->is($order))->toBeTrue();
});
```

---

## 12. Where to go next

- **`spec.md`** — the user stories, functional requirements, success criteria, and assumptions.
- **`data-model.md`** — the 10 tables, the 15 enums, and the state machines.
- **`contracts/workflow-engine.md`** — the full `WorkflowEngine` service contract.
- **`contracts/host-contracts.md`** — the six host-supplied contracts (`CustomAuthorizer`, `CustomConditionEvaluator`, `CustomActionHandler`, `CustomStepHandler`, `CustomResolver`, `TenantScopeProvider`).
- **`research.md`** — the technical decisions and the alternatives that were considered.

The next step is `/speckit.tasks`, which will produce a dependency-ordered, implementation-ready task list.
