# Workflow Engine — Business Requirements Document (BRD)

> **Scope of this file:** Business rules and behavior only. No code, no SQL.
> Schema/types live in [`ERD.md`](./ERD.md). All status/type lifecycles live in [`STATE_MACHINES.md`](./STATE_MACHINES.md).

---

## 1. Purpose

A reusable Laravel package that provides a **generic workflow engine**. Any host application can attach a workflow to any of its models (orders, leave requests, deployments, documents, …) and drive that record through a configurable set of steps with authorization, conditional routing, automated actions, and a full audit trail.

The engine must serve three workflow families through a single model:

| Type | Code | Nature | Step progression |
|---|---|---|---|
| Automation pipeline | `automation` | System-driven | Steps execute automatically and chain forward when conditions pass. |
| Approval workflow | `approval` | Human-driven | Steps wait for an authorized actor to perform an action (approve / reject / return …). |
| Generic state machine | `generic` | Mixed | Free-form steps that mix manual and automated transitions. |

The same engine, tables, and runtime serve all three. The `type` is a behavioral hint that controls defaults (e.g. whether a step auto-executes), not a separate code path.

---

## 2. Glossary

- **Workflow (Definition):** A versioned blueprint describing steps, transitions, conditions, and actions. Design-time.
- **Step:** A node in a workflow (start, task, approval, automated, gateway, end).
- **Transition:** A directed edge between two steps; may be triggered by an action and/or guarded by a condition.
- **Action:** A named operation an actor (or the system) can perform on the current step (e.g. `approve`, `reject`, `skip`, `return`, `submit`, or a custom action).
- **Condition:** A reusable guard evaluated at runtime. Either **general** (attribute/operator expression) or **custom** (host-provided evaluator class).
- **Instance:** A runtime execution of a workflow bound to one subject record. Run-time.
- **Step Instance:** The runtime record of a single step within an instance.
- **Assignee:** A target eligible to act on a step — a role, a permission, specific users, the public, or a custom resolver.
- **Subject (`workflowable`):** The host model record flowing through the workflow (polymorphic).
- **Actor:** The user (or `system`) that performed an event.

---

## 3. Actors

- **Workflow Designer / Admin:** Creates and versions workflow definitions, steps, transitions, conditions, and actions.
- **Participant:** An end user who acts on assigned/eligible steps (approver, reviewer, …).
- **System:** Executes automated steps and automatic transitions; recorded as actor `system` (no user id).
- **Host Application:** Starts instances, supplies the subject, the runtime context, and custom authorizer/evaluator/handler classes.

---

## 4. Design-Time Requirements (Definitions)

### 4.1 Workflow definitions
- BR-D-01 — A workflow has a human name and a unique machine `code` (unique per tenant when tenancy is enabled).
- BR-D-02 — A workflow declares one `type` (`automation` | `approval` | `generic`).
- BR-D-03 — A workflow MAY declare a target `subject_type` (the model class it applies to); if null, it is generic and can bind to any subject.
- BR-D-04 — A workflow is **versioned**. Creating a new version clones the definition; only one version per `code` is `is_current_version = true`.
- BR-D-05 — A workflow has a `status` lifecycle: `draft → active → archived` (see State Machines). Only `active` workflows may start new instances.
- BR-D-06 — Editing the structure (steps/transitions/conditions/actions) of a workflow that already has live instances MUST be done by creating a new version. Existing instances keep running on the version they started with (`workflow_version` is pinned on the instance).
- BR-D-07 — A workflow MUST declare exactly one start step and at least one end step before it can be activated.

### 4.2 Steps
- BR-S-01 — Each step has a `code` unique within its workflow, a `type`, and an ordering `position`.
- BR-S-02 — Step types: `start`, `task`, `approval`, `automated`, `gateway` (condition/branch), `end`.
- BR-S-03 — `automated` steps execute a `handler` and transition without waiting for a human. `approval`/`task` steps wait for an action from an eligible actor.
- BR-S-04 — Each step declares an `authorization_mode`: `public`, `roles`, `permissions`, `users`, or `custom`.
- BR-S-05 — A step may carry multiple assignee rows. A `match_mode` (`any` | `all`) controls whether matching one or all assignees grants eligibility (default `any`).
- BR-S-06 — A step declares `is_skippable` and `is_returnable` flags. Skip/return are only possible when the corresponding flag is true **and** the action’s guard passes.
- BR-S-07 — A step MAY declare an SLA/timeout (duration) used to compute `due_at` on its step instances and to drive escalation events.

### 4.3 Authorization model (per step)
- BR-A-01 — `public`: any authenticated user (or anonymous, if the host allows) is eligible.
- BR-A-02 — `roles`: eligible if the user holds **any** (or **all**, per `match_mode`) of the listed roles. Role names are opaque strings resolved against the host’s authorization layer (e.g. Spatie, Gates).
- BR-A-03 — `permissions`: same as roles but evaluated against permission names.
- BR-A-04 — `users`: eligible if the user id is in the explicit assignee list.
- BR-A-05 — `custom`: the step’s `custom_authorizer` class is invoked with `(user, instance, stepInstance)` and returns a boolean. This satisfies "available actions based on custom logic".
- BR-A-06 — Assignee targets are resolved at runtime, not frozen at design time, so role membership changes take effect immediately.

### 4.4 Actions
- BR-AC-01 — Each step defines the set of actions available on it. Built-in action types: `submit`, `approve`, `reject`, `skip`, `return`, `complete`, `cancel`; plus `custom`.
- BR-AC-02 — Each action declares an `availability_mode`: `general` (always available to any eligible actor) or `conditional`/`custom` (gated by a guard condition / guard class).
- BR-AC-03 — An action MAY require a comment (`requires_comment = true`); the engine rejects the action if no comment is supplied.
- BR-AC-04 — An action MAY declare an explicit `target_step` (where it routes). If absent, routing is resolved via transitions/conditions (BR-R-*).
- BR-AC-05 — An action MAY declare a `handler` class executed as a side-effect when performed (e.g. send notification, call API).

### 4.5 Conditions
- BR-C-01 — A condition is either **general** (`kind = expression`) — a structured set of `field / operator / value` clauses combined with AND/OR groups — or **custom** (`kind = custom`) — a host evaluator class invoked with `(instance, subject, context, user)` returning a boolean. A `composite` kind combines other conditions.
- BR-C-02 — General expression fields may reference the subject’s attributes and the instance `context` data bag.
- BR-C-03 — Conditions are reusable: a condition may be defined once and referenced by multiple transitions and actions.
- BR-C-04 — Conditions drive: transition guards, action availability, skip eligibility, and return eligibility.

### 4.6 Transitions & routing
- BR-R-01 — A transition connects `from_step → to_step` and has a `type`: `forward`, `skip`, `return`, `conditional`, or `automatic`.
- BR-R-02 — A transition MAY be bound to a triggering action and/or a guard condition.
- BR-R-03 — `automatic` and `conditional` transitions are evaluated by descending `priority`; the first whose guard passes is taken.
- BR-R-04 — `return` transitions move the instance to an earlier step; `skip` transitions bypass one or more steps forward.
- BR-R-05 — If no transition matches and the step is not an `end`, the engine resolves the next step by ascending `position` (sequential fallback), unless the workflow is configured to require explicit transitions.

---

## 5. Run-Time Requirements (Execution)

### 5.1 Instantiation
- BR-X-01 — The host starts an instance by binding an `active` workflow to a subject record and an optional initial `context`.
- BR-X-02 — On start, the instance pins `workflow_version`, sets `status` per the instance state machine, enters the start step, and writes a `started` history entry.
- BR-X-03 — A subject MAY have more than one instance over time; the engine does not forbid concurrent instances unless the host opts in to single-active enforcement.

### 5.2 Current step
- BR-X-04 — The engine always exposes the **current step** of an instance (the active step instance). A request "get current step" returns the active step instance plus its step definition.
- BR-X-05 — For parallel/branching support (gateway), more than one step instance MAY be `active` at once; "current step" then returns the set of active step instances.

### 5.3 Available-actions resolution (for a given user)
This is the core read operation and MUST be deterministic:

1. BR-X-06 — Resolve the active step instance(s) of the instance.
2. BR-X-07 — **Eligibility:** evaluate the step’s `authorization_mode` against the user (BR-A-*). If not eligible → return an empty action set.
3. BR-X-08 — **Action gathering:** collect the step’s actions.
4. BR-X-09 — For each action, evaluate `availability_mode`: `general` → available; `conditional` → guard condition must pass; `custom` → guard class must return true. (General vs custom logic, as required.)
5. BR-X-10 — Return the available actions with their metadata (`requires_comment`, `target_step`, `type`, label).

### 5.4 Performing an action (advance)
- BR-X-11 — When an eligible actor performs an available action: validate eligibility + availability again (server-side), enforce `requires_comment`, run the action `handler` if any, then route.
- BR-X-12 — Routing: use the action’s explicit `target_step` if set; else evaluate matching transitions by priority; else sequential fallback (BR-R-05).
- BR-X-13 — The leaving step instance is closed with the appropriate terminal status (`completed`, `skipped`, `returned`, `rejected`), `acted_by`, and `action_taken`.
- BR-X-14 — The arriving step instance is created in `active`, with `entered_at` and computed `due_at`.
- BR-X-15 — Every action writes a history entry (BR-H-*).

### 5.5 Skip
- BR-X-16 — A skip is allowed only when the current step `is_skippable = true` and the skip action/transition guard passes.
- BR-X-17 — Skipped step instances are recorded with status `skipped` and a history entry; routing proceeds to the skip target (explicit, else next by position).

### 5.6 Return (rollback)
- BR-X-18 — A return is allowed only when the current step `is_returnable = true` and the return guard passes.
- BR-X-19 — Return routes to an earlier step (explicit `target_step`, else the last completed step). The current step instance is closed as `returned`; the target step is re-entered as a **new** active step instance (history is never overwritten).
- BR-X-20 — Returning never deletes prior history; it appends `returned` and `step_entered` events.

### 5.7 Automation pipelines
- BR-X-21 — For `automated` steps the engine runs the step handler immediately on entry, then evaluates automatic transitions and advances without human input.
- BR-X-22 — A failed automated step sets the step instance to `failed` and the instance to `failed`; the host may retry (a retry re-enters the step as a new step instance). Retry/backoff policy is host-configurable.
- BR-X-23 — Automation chains continue until a human-gated step, an end step, or a failure is reached.

### 5.8 Approval workflows
- BR-X-24 — On entry to an `approval`/`task` step, the engine MAY materialize runtime assignments (one per eligible user or per the resolution policy) to power a task inbox.
- BR-X-25 — A `match_mode` on the step controls completion: `any` (first eligible actor’s action decides) or `all` (all assignees must act before the step completes — quorum/unanimous).
- BR-X-26 — Rejection routes per the `reject` action/transition (often to a `return` target or an `end`/rejected terminal).

### 5.9 History & activities
- BR-H-01 — Every meaningful event is appended to an immutable history log: `started`, `step_entered`, `step_completed`, `action_performed`, `skipped`, `returned`, `completed`, `cancelled`, `comment_added`, `error`.
- BR-H-02 — Each history entry records: instance, step instance (if any), `from_step`, `to_step`, `action_code`, `event`, actor (user id or `system`), optional comment, and a metadata/changes bag.
- BR-H-03 — History is append-only: no updates, no soft deletes. It is the system of record for audit.
- BR-H-04 — "Activities" (a chronological feed for UI) are derived from the history log; no separate writable store is required.

---

## 6. Multi-Tenancy (optional)
- BR-T-01 — All definition and instance tables carry a nullable `tenant_id`. When the host enables tenancy, the package applies the host-provided tenant scope; when disabled, `tenant_id` stays null and is ignored.
- BR-T-02 — Workflow `code` uniqueness is scoped per tenant when tenancy is enabled.

---

## 7. Non-Functional Notes
- Engine must be storage-agnostic at the host level: user references are nullable `BIGINT` pointing to the host `users` table; the table prefix is configurable.
- All type/status fields are `VARCHAR` constrained by application-level PHP enums/constants — **no `lookup_types`/`lookups` tables and no database `ENUM`** (per project instruction).
- Custom authorizers, condition evaluators, and action/step handlers are resolved via the host’s service container by class reference stored as a string.
- The engine must be safe under concurrent action attempts on the same step (re-validate server-side; first valid action wins for `any` mode).

---

## 8. Out of Scope (v1)
- A visual drag-and-drop workflow builder UI (engine exposes the data model only).
- Time-travel / scheduled future transitions beyond simple SLA `due_at` (cron-driven escalation hooks are provided; full scheduler is host responsibility).
- Cross-instance orchestration (sub-workflows can be modeled via automated steps that start child instances, but no first-class saga coordinator in v1).
