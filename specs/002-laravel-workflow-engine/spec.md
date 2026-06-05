# Feature Specification: Laravel Workflow Engine (Generic, Reusable Package)

**Feature Branch**: `002-laravel-workflow-engine`  
**Created**: 2026-06-05  
**Status**: Draft  
**Input**: User description: "we are make package for laravel framework from 10 to 13 for laravel workflow engine and all details inside @laravel-workflow-docs"

> Source of truth for business rules: [`laravel-workflow-docs/BRD.md`](../../laravel-workflow-docs/BRD.md).  
> Source of truth for data model: [`laravel-workflow-docs/ERD.md`](../../laravel-workflow-docs/ERD.md).  
> Source of truth for lifecycles: [`laravel-workflow-docs/STATE_MACHINES.md`](../../laravel-workflow-docs/STATE_MACHINES.md).

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Define and activate a workflow (Priority: P1)

A workflow designer (host application developer / admin) creates a versioned workflow with steps, actions, transitions, and conditions, then activates it for use.

**Why this priority**: Without a way to define and activate workflows, no instances can be started. This is the foundation of the engine.

**Independent Test**: A designer can create a workflow with one start step, at least one end step, and a transition between them, activate it, and confirm it is published for instance creation — without any instance ever being started.

**Acceptance Scenarios**:

1. **Given** a host application with the package installed, **When** a designer creates a workflow with one `start` step, one `task` step, and one `end` step connected by transitions, **Then** the workflow is saved in `draft` and cannot yet start instances.
2. **Given** a draft workflow with exactly one `start` and at least one `end` step, **When** the designer activates it, **Then** the workflow transitions to `active` and is now eligible to start new instances.
3. **Given** a draft workflow with zero `start` steps or zero `end` steps, **When** the designer attempts to activate it, **Then** the engine rejects activation and reports the validation error.
4. **Given** an `active` workflow that has live instances, **When** the designer needs to change the step structure, **Then** the engine requires creating a new version; live instances continue to run on the version they started with.

---

### User Story 2 - Start a workflow instance on a host model record (Priority: P1)

A host application binds an `active` workflow to one of its business records (e.g. an `Order`, `LeaveRequest`, or `Document`) and starts a runtime instance that enters the workflow's start step.

**Why this priority**: This is the primary entry point of the engine. Every other runtime feature depends on instances existing.

**Independent Test**: A developer can take any host model record, start a workflow instance on it, and read back the current step — without ever performing an action.

**Acceptance Scenarios**:

1. **Given** an `active` workflow and any host model record, **When** the host application starts an instance on that record, **Then** a new instance is created in `in_progress`, pinned to the current workflow version, and the start step becomes the active step.
2. **Given** a started instance, **When** the host queries it for the current step, **Then** the engine returns the active step instance together with its step definition.
3. **Given** a record that already has an instance running, **When** the host starts another instance on the same record, **Then** the engine does not block the second instance unless the host opts into single-active enforcement.
4. **Given** an attempted start, **When** the engine writes the `started` event, **Then** a history entry is appended with the initiator as the actor.

---

### User Story 3 - Resolve available actions and perform one to advance (Priority: P1)

A participant (a real user in the host application) needs to know what they can do on a given record's workflow, and to perform one of those actions so the workflow advances.

**Why this priority**: This is the engine's core read–write operation. Without it, no workflow can move forward.

**Independent Test**: For any user and any instance, the system can list the actions that user is allowed to perform right now, and the user can perform exactly one of them to move the workflow forward.

**Acceptance Scenarios**:

1. **Given** an instance on an `approval` step and a logged-in participant, **When** the participant requests available actions, **Then** the engine returns only the actions they are eligible to perform and that are currently available (post-guard evaluation); non-eligible participants receive an empty action set.
2. **Given** an available action, **When** the participant performs it, **Then** the engine re-validates eligibility and availability server-side, runs the action's handler (if any), closes the current step instance with the appropriate terminal status, and opens the next step instance with `entered_at` and computed `due_at`.
3. **Given** an action declared as `requires_comment = true`, **When** the participant submits it without a comment, **Then** the engine rejects the action and does not change instance state.
4. **Given** two participants performing actions on the same `match_mode = any` step at nearly the same time, **When** the engine processes the requests, **Then** the first valid action decides the step; the remaining pending assignments are marked `expired`.

---

### User Story 4 - Skip and return while preserving history (Priority: P2)

A participant who is allowed to skip the current step or return it to an earlier step can do so, and the full audit trail is preserved (never overwritten).

**Why this priority**: Skip and return are common in real approval flows; they are the second most common interactions after the basic advance.

**Independent Test**: A participant can skip a step that is marked skippable, or return a step that is marked returnable, and the history shows both the leaving event and the re-entry event.

**Acceptance Scenarios**:

1. **Given** an active step with `is_skippable = true` and a passing skip guard, **When** a participant performs the skip action, **Then** the step instance is closed as `skipped` and the instance routes to the skip target (explicit or next by position), appending a `skipped` history event.
2. **Given** an active step with `is_returnable = true` and a passing return guard, **When** a participant performs the return action, **Then** the current step instance is closed as `returned` and the target earlier step is re-entered as a **new** active step instance; both `returned` and `step_entered` events are appended.
3. **Given** any skip or return, **When** the history is queried, **Then** no prior history entry is modified or deleted; the audit trail is strictly append-only.

---

### User Story 5 - Run an automation pipeline without human input (Priority: P2)

For `automation` workflows, the system executes automated steps in sequence, evaluates automatic and conditional transitions, and only stops at a human-gated step, an end step, or a failure.

**Why this priority**: Automation is one of the three workflow families; without it the engine would not deliver on the "generic" promise.

**Independent Test**: A pure-automation workflow with no human-gated steps can run from start to end in one engine call without any participant action.

**Acceptance Scenarios**:

1. **Given** an `automation` workflow whose steps are all `automated` until an `end` step, **When** the engine advances the instance, **Then** it executes each step's handler, applies automatic/conditional transitions by priority, and reaches `completed` without any human action.
2. **Given** an automated step whose handler throws, **When** the error is caught, **Then** the step instance is set to `failed` and the instance is set to `failed`; the host can retry, which re-enters the step as a new step instance.
3. **Given** a chain that reaches a human-gated step, **When** the engine cannot advance further, **Then** the instance pauses at that step and remains queryable by participants.

---

### User Story 6 - Audit a workflow via the activity feed (Priority: P2)

An auditor (or any participant) can view the chronological activity feed of a workflow instance, derived from the append-only history log.

**Why this priority**: Auditing is required for compliance and for understanding the state of any business record; it depends on the history being well-formed.

**Independent Test**: After any sequence of events on an instance, the activity feed lists them in order with actor, action, comment, and timestamp.

**Acceptance Scenarios**:

1. **Given** an instance that has gone through several steps and actions, **When** the auditor requests the activity feed, **Then** the engine returns a chronological list of all events with actor (user or `system`), action, comment, and timestamp.
2. **Given** a `skipped` or `returned` event in the feed, **When** the auditor inspects it, **Then** the from/to steps and the reason are visible.
3. **Given** an instance, **When** the engine records a new event, **Then** the feed reflects it on the next read; history is never updated or deleted.

---

### User Story 7 - Isolate data by tenant when tenancy is enabled (Priority: P3)

A host application that operates in a multi-tenant mode (e.g. SaaS) needs the package to scope its queries and uniqueness rules to the current tenant, supplied by the host.

**Why this priority**: Important for SaaS deployments but optional for single-tenant apps; hence P3.

**Independent Test**: With tenancy enabled and two tenants, a workflow created in tenant A is not visible or startable in tenant B; with tenancy disabled, the package behaves as a single shared store.

**Acceptance Scenarios**:

1. **Given** the host has enabled tenancy and supplies a tenant scope, **When** any workflow, instance, or assignment is read or written, **Then** it is automatically scoped to the current `tenant_id`.
2. **Given** tenancy is enabled, **When** a designer tries to create a workflow whose `code` already exists within the same tenant, **Then** creation is rejected on uniqueness; the same `code` may exist in a different tenant.
3. **Given** the host has not enabled tenancy, **When** the package operates, **Then** `tenant_id` columns are null and ignored; uniqueness is global per code.

---

### Edge Cases

- What happens when a participant is eligible for a step but the only available actions are guarded and the guard fails? → The action set returned to that participant excludes the guarded action; if no actions remain, the set is empty.
- What happens when two participants act on the same step at the same time? → Server-side re-validation decides; for `match_mode = any` the first valid action wins, others are marked `expired`.
- What happens when an automated step handler throws? → The step instance and the instance are set to `failed`; the host can retry, which spawns a fresh step instance.
- What happens when a required-comment action is submitted with no comment? → The action is rejected before any state change.
- What happens when no transition matches and the workflow has `require_explicit_transitions = true`? → The instance stops at the current step with no auto-fallback; the host decides.
- What happens when a structural edit is attempted on a workflow with live instances? → The engine requires a new version; live instances continue on their pinned version.
- What happens when a return target is ambiguous (no explicit target, multiple earlier completed steps)? → The engine returns to the most recently completed step.
- What happens when the host's authorization layer (roles/permissions) changes after a step is defined? → Assignee resolution is performed at runtime, so changes take effect immediately on the next available-actions query.
- What happens when the package is installed on a host application that does not have a `users` table at the expected name? → The package only references `users` as a foreign-key target; the host must ensure the user model and table exist; the package does not own the user model.
- What happens when a workflow has parallel branches (gateway step) and more than one step instance is active? → The "current step" query returns the full set of active step instances; the engine treats them collectively.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Workflow definitions

- **FR-001**: System MUST support defining versioned workflows with a human name, a unique machine `code` per scope, one of three types (`automation` | `approval` | `generic`), and a lifecycle status (`draft` | `active` | `archived`). *(BR-D-01, BR-D-02, BR-D-05)*
- **FR-002**: System MUST allow a workflow to declare an optional target `subject_type` (a model class it applies to); when null, the workflow is generic and can bind to any subject. *(BR-D-03)*
- **FR-003**: System MUST pin the workflow version on every started instance; live instances continue to run on the version they started with. *(BR-D-04, BR-D-06, BR-X-02)*
- **FR-004**: System MUST require structural edits to a workflow that already has live instances to be done by creating a new version, never by mutating the in-use version. *(BR-D-06)*
- **FR-005**: System MUST allow a workflow to be activated only when it has exactly one `start` step and at least one `end` step. *(BR-D-07, STATE_MACHINES §1)*
- **FR-006**: System MUST support the full workflow lifecycle `draft → active → archived` and `archived → active`, refusing to start instances from any non-`active` workflow. *(STATE_MACHINES §1)*

#### Steps & authorization

- **FR-007**: System MUST support six step types: `start`, `task`, `approval`, `automated`, `gateway`, `end`. *(BR-S-02)*
- **FR-008**: System MUST support five per-step authorization modes: `public`, `roles`, `permissions`, `users`, `custom`; assignees are resolved at runtime so authorization changes take effect immediately. *(BR-S-04, BR-A-01..06)*
- **FR-009**: System MUST support a `match_mode` of `any` (first eligible actor decides) or `all` (quorum required) on a step. *(BR-S-05, BR-X-25)*
- **FR-010**: System MUST support per-step `is_skippable` and `is_returnable` flags; skip and return are only possible when the flag is true **and** the corresponding guard passes. *(BR-S-06, BR-X-16, BR-X-18)*
- **FR-011**: System MUST compute `due_at` on a step instance from the step's optional SLA and provide an escalation hook. *(BR-S-07)*

#### Actions & conditions

- **FR-012**: System MUST support the built-in action types `submit`, `approve`, `reject`, `skip`, `return`, `complete`, `cancel`, plus a `custom` action type. *(BR-AC-01)*
- **FR-013**: System MUST enforce `requires_comment` on actions that declare it, rejecting any action submitted without a comment. *(BR-AC-03)*
- **FR-014**: System MUST support three action availability modes: `general` (always available to eligible actors), `conditional` (gated by a reusable condition), `custom` (gated by a host class). *(BR-AC-02, BR-X-09)*
- **FR-015**: System MUST support three reusable condition kinds: `expression` (structured `field/operator/value` clauses combined with AND/OR groups), `custom` (host evaluator class), and `composite` (combines other conditions). *(BR-C-01..04)*

#### Transitions & routing

- **FR-016**: System MUST support transitions of type `forward`, `skip`, `return`, `conditional`, and `automatic`, with priority ordering so that the first matching guard wins. *(BR-R-01..03)*
- **FR-017**: System MUST support a sequential fallback by `position` when no transition matches, unless the workflow declares `require_explicit_transitions = true`. *(BR-R-05)*
- **FR-018**: System MUST allow an action to declare an explicit `target_step` that overrides transition evaluation. *(BR-AC-04, BR-X-12)*

#### Runtime

- **FR-019**: System MUST always expose the current step of an instance (the active step instance or, for parallel branches, the set of active step instances). *(BR-X-04, BR-X-05)*
- **FR-020**: System MUST resolve available actions for a given user, deterministically, by combining step eligibility and per-action availability evaluation. *(BR-X-06..10)*
- **FR-021**: System MUST advance an instance when an eligible actor performs an available action: re-validate server-side, run the action handler (if any), close the leaving step with the appropriate terminal status, open the entering step with `entered_at` and computed `due_at`, and append a history entry. *(BR-X-11..15)*
- **FR-022**: System MUST support skip and return that never mutate prior history; a return re-enters the target step as a new active step instance and appends both `returned` and `step_entered` events. *(BR-X-18..20)*
- **FR-023**: System MUST support automated steps that execute a handler on entry, then evaluate automatic/conditional transitions and chain forward without human input. *(BR-X-21..23)*
- **FR-024**: System MUST support approval workflows with optional runtime assignment materialization and `match_mode`-driven completion (first-acts vs quorum). *(BR-X-24..26)*
- **FR-025**: System MUST support the instance lifecycle `pending → in_progress → on_hold → in_progress → completed | rejected | failed | cancelled`, including retry of a failed step. *(STATE_MACHINES §2, §3)*
- **FR-026**: System MUST support holding, resuming, and cancelling an instance; cancellation closes remaining active steps. *(BR-X-25, STATE_MACHINES §2)*

#### History & audit

- **FR-027**: System MUST maintain an append-only history of all workflow events (`started`, `step_entered`, `step_completed`, `action_performed`, `skipped`, `returned`, `completed`, `cancelled`, `comment_added`, `error`) with actor (user or `system`), action, comment, from/to step, and metadata. *(BR-H-01..03)*
- **FR-028**: System MUST derive the activity feed directly from the history log; no separate writable activity store. *(BR-H-04)*

#### Multi-tenancy & configuration

- **FR-029**: System MUST support optional multi-tenancy via a host-provided tenant scope on all definition and instance tables; when disabled, `tenant_id` is null and ignored. *(BR-T-01)*
- **FR-030**: System MUST scope workflow `code` uniqueness per tenant when tenancy is enabled; uniqueness is global when tenancy is disabled. *(BR-T-02)*
- **FR-031**: System MUST allow the table prefix to be configured (`workflow_` by default). *(BR §7)*
- **FR-032**: System MUST reference host users only by nullable foreign keys; the package MUST NOT own the `users` table. *(BR §7)*
- **FR-033**: System MUST use application-level PHP enums/constants for all type/status fields; the package MUST NOT create `lookup_types` / `lookups` tables and MUST NOT use database `ENUM` types. *(BR §7)*

### Key Entities *(include if feature involves data)*

> Full column-level definition lives in [`laravel-workflow-docs/ERD.md`](../../laravel-workflow-docs/ERD.md).

- **Workflow (Definition)**: A versioned blueprint that describes a process as steps, transitions, conditions, and actions. Carries its own lifecycle status and the `is_current_version` flag. Has exactly one `start` step and at least one `end` step before activation.
- **Step**: A node in a workflow; typed (`start | task | approval | automated | gateway | end`), ordered by `position`, and decorated with authorization mode, optional handler, and skip/return flags.
- **Step Assignee**: A polymorphic authorization target for a step — a role name, a permission name, a specific user id, the public, or a custom resolver class.
- **Step Action**: A named operation offered on a step (built-in or custom), with availability mode, optional guard, optional `target_step`, optional `requires_comment`, and optional handler.
- **Condition**: A reusable guard of kind `expression` (structured clauses), `custom` (host evaluator class), or `composite` (combines other conditions). Drives transition guards, action availability, skip, and return.
- **Transition**: A directed edge between two steps with a type (`forward | skip | return | conditional | automatic`), an optional triggering action, an optional guard, and a priority.
- **Workflow Instance**: A running execution of a workflow pinned to a specific version, bound to one host model record via a polymorphic `workflowable` subject. Has a lifecycle status and a `context` data bag.
- **Step Instance**: The runtime record of a single step within an instance. Has a lifecycle status (`pending | active | completed | skipped | returned | rejected | failed`), `entered_at`, `completed_at`, `due_at`, `acted_by`, `action_taken`, and a step-local data bag.
- **Assignment**: A runtime task-inbox row linking a step instance to a specific user; lifecycle `pending | acted | reassigned | expired`. Used to drive quorum completion in `match_mode = all`.
- **History Entry**: An immutable, append-only event row recording one moment in an instance's life (actor, action, comment, from/to step, metadata). The activity feed is derived from it. Has no `updated_at` and is never soft-deleted.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A host application can start a workflow instance bound to any host model record and read back its current step in a single host-side call, with no manual SQL or service wiring beyond the published API.
- **SC-002**: For any user and any instance, the available-actions resolution returns a deterministic, server-validated set (same input → same output) and completes in under 100 ms at the 95th percentile for instances with up to 50 steps.
- **SC-003**: 100% of state-changing operations (start, action perform, skip, return, cancel, retry, automated handler invocation) produce an immutable history entry; no operation can leave the history untouched.
- **SC-004**: An action that violates eligibility, availability, or `requires_comment` is rejected server-side before any instance or step state is changed, and the rejection is observable to the caller.
- **SC-005**: A workflow can be activated only when it has exactly one `start` step and at least one `end` step; the engine refuses to activate any other shape and returns a validation error.
- **SC-006**: An `automation` workflow with no human-gated steps advances from `start` to an `end` step and reaches the `completed` terminal state in a single engine call without any participant action.
- **SC-007**: Skip and return preserve the full prior history (no entries are ever updated, soft-deleted, or removed); the feed shows both the leaving event and, for return, a new `step_entered` event for the re-entered step.
- **SC-008**: An instance keeps running on the workflow version it was started with, regardless of any new versions created for that workflow afterwards.
- **SC-009**: When multi-tenancy is enabled by the host, workflow, instance, and assignment reads and writes are scoped to the current `tenant_id`, and workflow `code` uniqueness is enforced per tenant.
- **SC-010**: The package installs and the test suite passes on the four target host framework major releases (Laravel 10, 11, 12, 13) using a host-provided user model and table.
- **SC-011**: First-valid-action-wins semantics hold for `match_mode = any` steps: when two eligible actors submit actions on the same step, the engine accepts exactly one and marks the other pending assignments `expired`; for `match_mode = all`, the step completes only after every assignment has been acted on.
- **SC-012**: All `type` and `status` fields are validated against application-level PHP enums/constants; the engine never reads these from `lookup_*` tables and never relies on database `ENUM` types.

---

## Assumptions

- **Host framework support**: The package targets the four major releases of the host framework: Laravel 10, Laravel 11, Laravel 12, and Laravel 13. The minimum supported PHP version is whatever is required by the lowest targeted Laravel release (per the host framework's documented requirements). Older framework majors are out of scope.
- **Authentication & authorization source**: The host application owns authentication and the authorization layer (e.g. role names, permission names, or a custom resolver). The package references the host's `users.id` only via nullable foreign keys and delegates role/permission/custom evaluation to host-supplied classes resolved through the host's class resolver.
- **Multi-tenancy is host-driven**: When the host enables tenancy, the host supplies a tenant scope and the package applies it to definition and instance tables. When the host does not enable tenancy, `tenant_id` columns stay null and are ignored. The package does not provide a default tenant resolver.
- **Visual workflow builder is out of scope**: v1 exposes the data model and a programmatic API; it does NOT ship a drag-and-drop UI builder. Workflows are defined via code, seeders, or host-built admin UIs.
- **Cross-instance orchestration is out of scope for v1**: Sub-workflows can be modeled via `automated` steps that start child instances, but no first-class saga coordinator is provided.
- **Scheduling and escalation**: The package computes `due_at` from per-step SLA and emits an escalation hook, but the actual scheduler is the host's responsibility (cron, scheduled tasks, or a queue-based job runner).
- **Action handler execution model**: Action handlers and automated-step handlers run synchronously by default. Hosts that need asynchronous side-effects may dispatch jobs from within their handler classes; the engine itself does not queue handler execution.
- **Definition and runtime separation**: The engine keeps a clear split between design-time data (workflows, steps, transitions, conditions, actions) and run-time data (instances, step instances, assignments, history). The same engine, tables, and runtime serve all three workflow types (`automation`, `approval`, `generic`); `type` is a behavioral hint that controls defaults (e.g. whether a step auto-executes), not a separate code path.
- **All enumerated values are application-defined**: `workflows.type`, `workflows.status`, `workflow_steps.type`, `workflow_steps.authorization_mode`, `workflow_steps.match_mode`, `workflow_step_assignees.assignee_type`, `workflow_step_actions.type`, `workflow_step_actions.availability_mode`, `workflow_conditions.kind`, `workflow_transitions.type`, `workflow_instances.status`, `workflow_step_instances.status`, `workflow_assignments.status`, `workflow_histories.event`, and `workflow_histories.actor_type` are `VARCHAR` columns constrained by PHP enums/constants. The package does not create `lookup_types` or `lookups` tables and does not use database `ENUM` types.
- **Schema conventions**: `BIGINT` internal primary keys, `UUID` v4 public keys, `TIMESTAMPTZ` timestamps, soft delete (`is_deleted` + `deleted_at`), and full audit columns (`created_by` / `updated_by` / `deleted_by`) on all tables except the append-only `workflow_histories` (which has only `created_at`).
- **Concurrency model**: The engine re-validates eligibility and availability server-side on every action and relies on the host database for transactional consistency; for `match_mode = any` the first valid action wins. Formal row-level locking semantics are host/database dependent and are not abstracted away by the package.
- **Data retention**: Retention of history and instances is host-controlled; the package does not auto-prune or auto-archive rows.


