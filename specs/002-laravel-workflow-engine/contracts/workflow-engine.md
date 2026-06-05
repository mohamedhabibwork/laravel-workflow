# Public API Contract: `WorkflowEngine`

**Feature**: 002-laravel-workflow-engine
**Date**: 2026-06-05
**Status**: Complete

This document defines the **public service contract** of the workflow engine. It is the single entry point that host applications use. All other classes in `src/` are implementation details; this contract is the only one a host MUST know to use the engine.

The service is bound in the container as `HFlow\LaravelWorkflow\Contracts\WorkflowEngine` (interface) with a default implementation in `HFlow\LaravelWorkflow\Engines\WorkflowEngine`. Hosts can type-hint the contract in their constructors, or use the `LaravelWorkflow` facade.

---

## 1. Service binding

```php
// In a host's service provider, controller, or anywhere in the host app:
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;

public function __construct(private readonly WorkflowEngine $engine) {}
```

Or via the facade:

```php
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;

$instance = LaravelWorkflow::engine()->start($workflow, $order, ['requester_role' => 'manager']);
```

---

## 2. Method signatures

### 2.1 `start(Workflow|string $workflow, Model $subject, array $context = [], ?User $initiator = null): WorkflowInstance`

Start a new workflow instance bound to a host model record.

| Parameter | Type | Description |
|---|---|---|
| `$workflow` | `Workflow` (model) or `string` (workflow `code`) | The workflow to instantiate. If a string, the engine resolves the current version. |
| `$subject` | `Illuminate\Database\Eloquent\Model` | The host model record to bind to. Stored as a polymorphic `workflowable`. |
| `$context` | `array` (optional) | Initial runtime data bag. Available to conditions and handlers. |
| `$initiator` | `Illuminate\Foundation\Auth\User\|null` (optional) | The user starting the instance. If `null`, the actor is recorded as `system`. |

**Returns**: the newly created `WorkflowInstance` (already advanced to `in_progress` and entered the start step).

**Throws**:
- `InvalidWorkflowException` — the workflow is not `active`.
- `WorkflowSubjectMismatchException` — the subject's class is not assignable to the workflow's `subject_type` (if set).

**Side effects**:
- Inserts a `workflow_instances` row with `status = in_progress`, `workflow_version` pinned, and `started_at` set.
- Inserts a `workflow_step_instances` row for the start step with `status = active`, `entered_at` set, `due_at` computed.
- Inserts a `workflow_histories` row with `event = started`.

**Spec mapping**: US-2, BR-X-01..03, FR-003, FR-019.

---

### 2.2 `currentStep(WorkflowInstance $instance): WorkflowStepInstance|Collection`

Get the active step instance (or, for parallel branches via `gateway` steps, the set of active step instances) of an instance.

| Parameter | Type | Description |
|---|---|---|
| `$instance` | `WorkflowInstance` | The instance to query. |

**Returns**:
- A single `WorkflowStepInstance` when exactly one step is active.
- An `Illuminate\Support\Collection<WorkflowStepInstance>` when more than one is active (parallel branches).

**Throws**: never. An instance in a terminal state returns its last active step instance (or an empty collection if none).

**Spec mapping**: US-2, BR-X-04..05, FR-019.

---

### 2.3 `availableActions(WorkflowInstance $instance, ?User $user = null): ActionSet`

Resolve the set of actions a user may perform on the instance right now, deterministically.

| Parameter | Type | Description |
|---|---|---|
| `$instance` | `WorkflowInstance` | The instance to query. |
| `$user` | `User\|null` | The user to check eligibility for. If `null`, only `public`-mode steps return actions. |

**Returns**: an `HFlow\LaravelWorkflow\ActionSet` value object — an ordered list of `HFlow\LaravelWorkflow\Action` value objects, each carrying:

| Property | Type | Description |
|---|---|---|
| `code` | `string` | The action's `code` (e.g. `approve`, `reject`). |
| `name` | `string` | The action's display name. |
| `label` | `string\|null` | UI label, if set. |
| `type` | `ActionType` | The action's type enum. |
| `requiresComment` | `bool` | Whether the engine will reject the action without a comment. |
| `targetStep` | `WorkflowStep\|null` | Explicit route, if set. |
| `sortOrder` | `int` | For UI ordering. |

The engine **re-evaluates** eligibility and availability server-side on every call; the result is always a snapshot of the current state.

**Spec mapping**: US-3, BR-X-06..10, FR-020.

---

### 2.4 `perform(WorkflowInstance $instance, string $actionCode, ?User $user = null, ?array $payload = null): WorkflowInstance`

Perform an action and advance the instance.

| Parameter | Type | Description |
|---|---|---|
| `$instance` | `WorkflowInstance` | The instance to advance. |
| `$actionCode` | `string` | The `code` of the action to perform. |
| `$user` | `User\|null` | The actor. If `null`, the actor is recorded as `system` (e.g. for automation). |
| `$payload` | `array\|null` | Optional payload — keys include `comment` (string), `target_step_id` (int), `metadata` (array). |

**Returns**: the updated `WorkflowInstance` (the same instance, refreshed).

**Throws**:
- `NotEligibleException` — the user is not eligible for the current step.
- `ActionNotAvailableException` — the action is not in the resolved set (e.g. its guard failed).
- `CommentRequiredException` — the action requires a comment and none was supplied.
- `TransitionNotFoundException` — no transition matches and `require_explicit_transitions = true`.
- `WorkflowTerminalException` — the instance is already in a terminal state.

**Side effects**:
- Closes the leaving `workflow_step_instances` row with the appropriate terminal status.
- Opens the entering `workflow_step_instances` row with `status = active`, `entered_at` set, `due_at` computed.
- Calls the action's handler (if set) inside a try/catch; handler errors do not corrupt instance state.
- Appends one or more `workflow_histories` rows (`step_completed`, `action_performed`, `step_entered`).
- For `match_mode = any`, marks other pending assignments as `expired` (history appended).

**Spec mapping**: US-3, BR-X-11..15, FR-021.

---

### 2.5 `skip(WorkflowInstance $instance, ?User $user = null, ?string $comment = null): WorkflowInstance`

Skip the current step.

| Parameter | Type | Description |
|---|---|---|
| `$instance` | `WorkflowInstance` | The instance to advance. |
| `$user` | `User\|null` | The actor. |
| `$comment` | `string\|null` | Optional comment, appended to the `skipped` history entry. |

**Returns**: the updated `WorkflowInstance`.

**Throws**:
- `SkipNotAllowedException` — the step's `is_skippable` is false or the skip guard failed.
- `WorkflowTerminalException` — the instance is in a terminal state.

**Side effects**:
- Closes the current step instance with `status = skipped`.
- Routes per the skip transition (explicit `target_step` else next by `position`).
- Opens the entering step instance; appends `skipped` + `step_entered` history.

**Spec mapping**: US-4, BR-X-16..17, FR-022.

---

### 2.6 `return(WorkflowInstance $instance, WorkflowStep|string|null $targetStep = null, ?User $user = null, ?string $comment = null): WorkflowInstance`

Return to an earlier step.

| Parameter | Type | Description |
|---|---|---|
| `$instance` | `WorkflowInstance` | The instance to roll back. |
| `$targetStep` | `WorkflowStep\|string\|null` | The step to return to. If `null`, the engine returns to the most recently completed step. If a string, the `code` of the target step. |
| `$user` | `User\|null` | The actor. |
| `$comment` | `string\|null` | Optional comment. |

**Returns**: the updated `WorkflowInstance`.

**Throws**:
- `ReturnNotAllowedException` — the step's `is_returnable` is false or the return guard failed.
- `WorkflowTerminalException` — the instance is in a terminal state.

**Side effects**:
- Closes the current step instance with `status = returned`.
- Opens a **new** active step instance for the target step (history is never overwritten).
- Appends `returned` + `step_entered` history. Prior history is untouched.

**Spec mapping**: US-4, BR-X-18..20, FR-022.

---

### 2.7 `cancel(WorkflowInstance $instance, ?User $user = null, ?string $comment = null): WorkflowInstance`

Cancel the instance.

| Parameter | Type | Description |
|---|---|---|
| `$instance` | `WorkflowInstance` | The instance to cancel. |
| `$user` | `User\|null` | The actor. |
| `$comment` | `string\|null` | Optional comment. |

**Returns**: the updated `WorkflowInstance` (now in `cancelled`).

**Throws**:
- `WorkflowTerminalException` — the instance is already in a terminal state.

**Side effects**:
- Closes all remaining active step instances with `status = skipped` and `comment = "cancelled with instance"`.
- Appends `cancelled` history.
- Sets `workflow_instances.status = cancelled` and `completed_at = now()`.

**Spec mapping**: BR-X-25 (cancelled transition), FR-026.

---

### 2.8 `hold(WorkflowInstance $instance, ?User $user = null, ?string $comment = null): WorkflowInstance`

Put the instance on hold.

**Returns**: the updated `WorkflowInstance` (now in `on_hold`).

**Throws**: `WorkflowTerminalException` if already terminal.

**Spec mapping**: BR-X-26, FR-026.

---

### 2.9 `resume(WorkflowInstance $instance, ?User $user = null, ?string $comment = null): WorkflowInstance`

Resume a held instance.

**Returns**: the updated `WorkflowInstance` (now in `in_progress`).

**Throws**: `InvalidStateException` if the instance is not on hold.

**Spec mapping**: BR-X-26, FR-026.

---

### 2.10 `retry(WorkflowInstance $instance, ?User $user = null, ?string $comment = null): WorkflowInstance`

Retry the failed step of a failed instance.

| Parameter | Type | Description |
|---|---|---|
| `$instance` | `WorkflowInstance` | The instance to retry. Must be in `failed`. |
| `$user` | `User\|null` | The actor. |
| `$comment` | `string\|null` | Optional comment. |

**Returns**: the updated `WorkflowInstance` (now in `in_progress` with a new active step instance for the failed step).

**Throws**: `InvalidStateException` if the instance is not in `failed`.

**Side effects**:
- Re-enters the failed step as a **new** step instance (history preserved).
- Appends `step_entered` history.

**Spec mapping**: BR-X-22, FR-025.

---

### 2.11 `history(WorkflowInstance $instance, ?int $limit = null, ?string $event = null): Collection`

Return the activity feed for an instance, derived from the history log.

| Parameter | Type | Description |
|---|---|---|
| `$instance` | `WorkflowInstance` | The instance to query. |
| `$limit` | `int\|null` | If set, return at most this many events (most recent first). |
| `$event` | `string\|null` | If set, filter to a single event type (e.g. `'action_performed'`). |

**Returns**: `Collection<WorkflowHistory>` ordered by `performed_at desc` (or `asc` if `$limit` is set and the host wants chronological — see `ActivityFeed`).

**Spec mapping**: US-6, BR-H-01..04, FR-027, FR-028.

---

### 2.12 `versions(Workflow|string $workflow): Collection`

Return all versions of a workflow (for designer UIs).

| Parameter | Type | Description |
|---|---|---|
| `$workflow` | `Workflow` or `string` (workflow `code`) | The workflow to look up. |

**Returns**: `Collection<Workflow>` ordered by `version desc`.

**Spec mapping**: US-1, BR-D-04..06.

---

### 2.13 `createNewVersion(Workflow $workflow, array $overrides = []): Workflow`

Create a new draft version of an existing workflow.

| Parameter | Type | Description |
|---|---|---|
| `$workflow` | `Workflow` | The source workflow (any status). |
| `$overrides` | `array` | Optional mutations to apply to the cloned structure (e.g. `['name' => 'Q2 update']`). |

**Returns**: a new `Workflow` with `version = $workflow->version + 1`, `is_current_version = false`, `status = draft`, and deep clones of all steps, transitions, conditions, actions, and assignees.

**Side effects**: does not touch any instance. The new version is `draft` until activated.

**Spec mapping**: US-1, BR-D-04, FR-004.

---

### 2.14 `activate(Workflow $workflow): Workflow`

Activate a draft workflow.

| Parameter | Type | Description |
|---|---|---|
| `$workflow` | `Workflow` | The workflow to activate. Must be in `draft`. |

**Returns**: the updated `Workflow` (now in `active`, with `is_current_version` flipped to true and the previous active version's `is_current_version` set to false).

**Throws**:
- `InvalidStateException` — the workflow is not in `draft`.
- `InvalidWorkflowException` — the workflow has 0 or more than 1 `start` step, or 0 `end` steps.

**Spec mapping**: US-1, BR-D-07, FR-005, FR-006.

---

## 3. Error model

All engine exceptions extend `HFlow\LaravelWorkflow\Exceptions\WorkflowException` (which extends `RuntimeException`). The exception class is the canonical machine-readable identifier; the message is human-readable.

| Exception | When |
|---|---|
| `WorkflowException` | Base class. Catch this to handle any engine error. |
| `InvalidWorkflowException` | Workflow is not active, fails activation validation, etc. |
| `InvalidStateException` | An operation requires the instance to be in a specific state and it isn't. |
| `NotEligibleException` | The user is not eligible for the current step. |
| `ActionNotAvailableException` | The action is not in the resolved available-actions set. |
| `CommentRequiredException` | The action requires a comment and none was supplied. |
| `SkipNotAllowedException` | The step is not skippable or the skip guard failed. |
| `ReturnNotAllowedException` | The step is not returnable or the return guard failed. |
| `TransitionNotFoundException` | No transition matches and `require_explicit_transitions = true`. |
| `WorkflowTerminalException` | The instance is in a terminal state (`completed`, `rejected`, `cancelled`). |
| `WorkflowSubjectMismatchException` | The subject's class is not assignable to the workflow's `subject_type`. |
| `InvalidExpressionException` | The expression JSON is malformed, too deep, or too large. |
| `AutomationLoopGuardException` | The automation chain exceeded `max_chain_depth`. |

---

## 4. Determinism guarantees

- `availableActions($instance, $user)` is **deterministic**: given the same `$instance` and `$user`, it returns the same `ActionSet` (in the same order) on every call. This is testable in `tests/Integration/DeterminismTest.php`.
- `perform($instance, $actionCode, $user, $payload)` is **idempotent under retry** at the engine level: re-validating eligibility and re-evaluating the same action with the same state always produces the same outcome. Hosts that need exactly-once semantics must add their own idempotency keys; the engine does not abstract that.
- `history($instance)` is **chronological** for a given instance and **append-only**: the same `WorkflowHistory` row is never returned twice and never modified between calls.

---

## 5. Observability

- The engine dispatches a `WorkflowHistoryRecorded` Laravel event after every history INSERT. Hosts can listen for it (e.g. to broadcast, log, or send notifications).
- The engine does NOT broadcast, log, or send notifications itself. Those are host concerns.
- The engine does NOT depend on a logger; if a host attaches a logger to the engine's service, it will be used to log non-fatal warnings (e.g. handler errors caught and recorded as `error` history events). This is best-effort, not a contract.

---

## 6. Versioning of the contract

- The contract is the public API surface. Any breaking change to a method's signature, return type, or exception list is a **major version** bump.
- Additive changes (new methods, new optional parameters with defaults, new exception subclasses) are **minor version** bumps.
- The contract is tested in `tests/Contract/WorkflowEngineContractTest.php` and is part of the CI suite.
