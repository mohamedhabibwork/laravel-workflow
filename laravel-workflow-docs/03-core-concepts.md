# Core Concepts

## Workflow

A workflow is a versioned definition. It has a `code`, `name`, `type`, `status`, optional `subject_type`, and a graph of steps, actions, conditions, assignees, and transitions.

Workflow types:

- `automation`: mostly system-driven.
- `approval`: human approval flow.
- `generic`: mixed or custom state flow.

Workflow statuses:

- `draft`: editable and not startable.
- `active`: startable by hosts.
- `archived`: retained but not current.

## Version

Instances pin `workflow_version` when they start. New versions do not mutate live instances.

Use:

```php
$draft = $engine->createNewVersion($activeWorkflow, ['name' => 'Order Approval v2']);
$engine->activate($draft);
```

## Step

A step is a node in the workflow graph.

Step types:

- `start`: required entry point. Exactly one per activatable workflow.
- `task`: manual work.
- `approval`: manual approval work, often with assignments.
- `automated`: runs a `CustomStepHandler`.
- `gateway`: branching or fan-in/fan-out marker.
- `end`: terminal workflow destination. At least one per activatable workflow.

## Action

An action is something an actor can perform at a step, such as `submit`, `approve`, or `reject`. The engine returns available actions as an ordered `ActionSet`.

Every `perform()` call re-checks both:

- user eligibility for the current step
- action availability for the requested action

## Transition

A transition routes from one step to another. Routing order:

1. Explicit `target_step_id` on the action.
2. Highest-priority matching transition.
3. Sequential fallback by step `position` when `require_explicit_transitions = false`.
4. `TransitionNotFoundException`.

## Condition

Conditions are reusable guards for action availability, transitions, skip, and return. Built-in expression conditions support field paths like `subject.amount`, `context.requester_role`, `user.id`, and `instance.status`.

## Instance

A workflow instance is a running copy of a workflow bound to a host model:

```php
$instance = $engine->start($workflow, $order, ['source' => 'checkout'], auth()->user());
```

The instance stores:

- pinned workflow id and version
- subject type and id
- current status
- current step id
- runtime context

## Step Instance

A step instance records one visit to a step. Returning to a step creates a new step instance instead of rewriting the old one.

## Assignment

Assignments represent pending/acted/expired work for approval/task steps. For `match_mode = any`, the first valid actor can complete the step and other pending assignments expire.

## History

History is append-only. It is the canonical audit log and powers the activity feed.

