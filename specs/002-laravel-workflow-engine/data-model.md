# Data Model: Laravel Workflow Engine

**Feature**: 002-laravel-workflow-engine
**Date**: 2026-06-05
**Status**: Complete

> Source of truth for column-level definitions: [`laravel-workflow-docs/ERD.md`](../../../laravel-workflow-docs/ERD.md).  
> Source of truth for status lifecycles: [`laravel-workflow-docs/STATE_MACHINES.md`](../../../laravel-workflow-docs/STATE_MACHINES.md).  
> Source of truth for business rules: [`laravel-workflow-docs/BRD.md`](../../../laravel-workflow-docs/BRD.md).

This document is a developer-facing restatement of the data model. It groups the 10 tables into **Definition** (design-time) and **Runtime** (execution-time) sections, lists the Eloquent model class names, the field types, the indexes, the relationships, and the state transitions each row can undergo.

---

## 0. Conventions (applied to every table)

| Concern | Convention |
|---|---|
| Internal PK | `id` — `BIGINT` (auto-increment / `BIGSERIAL`) |
| Public key | `uuid` — `UUID` v4, unique |
| Timestamps | `created_at`, `updated_at`, `deleted_at` — all `TIMESTAMPTZ` |
| Soft delete | `is_deleted` `BOOLEAN` (default false) + `deleted_at` |
| Audit columns | `created_by`, `updated_by`, `deleted_by` — `BIGINT`, nullable, → host `users.id` |
| Type / status fields | `VARCHAR` constrained by **application PHP enums/constants** — no `lookup_*` tables, no DB `ENUM` |
| User references | nullable `BIGINT` → host `users.id` (the package does not own `users`) |
| Tenancy | `tenant_id` `BIGINT` nullable on definition + instance tables (optional) |
| Table prefix | configurable; `workflow_` shown here as default |
| JSON columns | `JSON`/`JSONB` for config and data bags |
| History exception | `workflow_histories` is **append-only** — it has `created_at` only (no `updated_at`, no soft-delete columns) |

The migration is **a single combined migration** published via `Package::hasMigration('create_workflow_table')`. The migration reads the configured table prefix at runtime so the same migration works for every host prefix.

---

## 1. Definition tables (design-time)

### 1.1 `workflows` — the versioned blueprint

**Eloquent model**: `HFlow\LaravelWorkflow\Models\Workflow`  
**State machine**: `WorkflowStateMachine` (states `draft | active | archived`)

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `uuid` | UUID UK | |
| `tenant_id` | BIGINT, null | optional tenancy |
| `name` | VARCHAR | display name |
| `code` | VARCHAR | machine name; unique per `(tenant_id, code, deleted_at)` |
| `description` | TEXT, null | |
| `type` | VARCHAR | `automation` \| `approval` \| `generic` (cast to `WorkflowType` enum) |
| `subject_type` | VARCHAR, null | target model class; null = generic |
| `version` | INT | default 1 |
| `is_current_version` | BOOLEAN | only one true per `code` |
| `status` | VARCHAR | `draft` \| `active` \| `archived` (cast to `WorkflowStatus` enum) |
| `start_step_id` | BIGINT, null FK → `workflow_steps.id` | entry point |
| `require_explicit_transitions` | BOOLEAN | disable sequential fallback if true |
| `config` | JSON, null | engine-level settings |

**Relationships**:
- `hasMany(WorkflowStep::class, 'workflow_id')` — `steps`
- `hasMany(WorkflowTransition::class, 'workflow_id')` — `transitions`
- `hasMany(WorkflowCondition::class, 'workflow_id')` — `conditions` (null `workflow_id` = global/reusable)
- `hasMany(WorkflowInstance::class, 'workflow_id')` — `instances`
- `belongsTo(WorkflowStep::class, 'start_step_id')` — `startStep`

**State transitions** (`WorkflowStateMachine`):
- `draft → active` (activate; guard: exactly one start step, ≥1 end step)
- `active → archived` (archive)
- `archived → active` (reactivate)
- `draft → archived` (discard)

**Indexes**:
- `(tenant_id, code)` unique, partial where `is_deleted = false` (database-specific; on SQLite we use a plain unique key with a generated column or rely on a check constraint via the model layer)
- `(status)` for fast "active workflows" lookups
- `(subject_type)` for "which workflows apply to this model class"

---

### 1.2 `workflow_steps` — nodes of a workflow

**Eloquent model**: `HFlow\LaravelWorkflow\Models\WorkflowStep`  
**No internal state machine** (steps are static definitions; their *instances* have one).

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `uuid` | UUID UK | |
| `workflow_id` | BIGINT FK → `workflows.id` | |
| `name` | VARCHAR | |
| `code` | VARCHAR | unique per `(workflow_id, code, deleted_at)` |
| `description` | TEXT, null | |
| `type` | VARCHAR | `start` \| `task` \| `approval` \| `automated` \| `gateway` \| `end` (cast to `StepType`) |
| `position` | INT | ordering / sequential fallback |
| `authorization_mode` | VARCHAR | `public` \| `roles` \| `permissions` \| `users` \| `custom` (cast to `AuthorizationMode`) |
| `match_mode` | VARCHAR | `any` \| `all` (cast to `MatchMode`) |
| `custom_authorizer` | VARCHAR, null | FQCN for `custom` mode |
| `handler` | VARCHAR, null | FQCN for `automated` steps |
| `is_skippable` | BOOLEAN | default false |
| `is_returnable` | BOOLEAN | default false |
| `sla_seconds` | INT, null | drives `due_at` + escalation |
| `config` | JSON, null | |

**Relationships**:
- `belongsTo(Workflow::class, 'workflow_id')` — `workflow`
- `hasMany(WorkflowStepAssignee::class, 'step_id')` — `assignees`
- `hasMany(WorkflowStepAction::class, 'step_id')` — `actions`
- `hasMany(WorkflowTransition::class, 'from_step_id')` — `outgoingTransitions`
- `hasMany(WorkflowTransition::class, 'to_step_id')` — `incomingTransitions`
- `hasMany(WorkflowStepInstance::class, 'step_id')` — `instances`

**Indexes**:
- `(workflow_id, code)` unique
- `(workflow_id, position)` for sequential fallback

---

### 1.3 `workflow_step_assignees` — polymorphic authorization targets

**Eloquent model**: `HFlow\LaravelWorkflow\Models\WorkflowStepAssignee`

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `uuid` | UUID UK | |
| `step_id` | BIGINT FK → `workflow_steps.id` | |
| `assignee_type` | VARCHAR | `role` \| `permission` \| `user` \| `public` \| `custom` (cast to `AssigneeType`) |
| `assignee_value` | VARCHAR, null | role/permission name, or user id as string |
| `custom_resolver` | VARCHAR, null | FQCN returning eligible users |
| `sort_order` | INT | |

**Relationships**:
- `belongsTo(WorkflowStep::class, 'step_id')` — `step`

**Indexes**:
- `(step_id, sort_order)` for ordered loading

---

### 1.4 `workflow_step_actions` — actions offered at a step

**Eloquent model**: `HFlow\LaravelWorkflow\Models\WorkflowStepAction`

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `uuid` | UUID UK | |
| `step_id` | BIGINT FK → `workflow_steps.id` | |
| `name` | VARCHAR | |
| `code` | VARCHAR | unique per `(step_id, code, deleted_at)` |
| `label` | VARCHAR, null | UI label |
| `type` | VARCHAR | `submit` \| `approve` \| `reject` \| `skip` \| `return` \| `complete` \| `cancel` \| `custom` (cast to `ActionType`) |
| `availability_mode` | VARCHAR | `general` \| `conditional` \| `custom` (cast to `ActionAvailabilityMode`) |
| `guard_condition_id` | BIGINT, null FK → `workflow_conditions.id` | for `conditional` |
| `guard_class` | VARCHAR, null | FQCN for `custom` availability |
| `target_step_id` | BIGINT, null FK → `workflow_steps.id` | explicit route |
| `requires_comment` | BOOLEAN | default false |
| `handler` | VARCHAR, null | FQCN side-effect handler |
| `sort_order` | INT | |

**Relationships**:
- `belongsTo(WorkflowStep::class, 'step_id')` — `step`
- `belongsTo(WorkflowCondition::class, 'guard_condition_id')` — `guardCondition`
- `belongsTo(WorkflowStep::class, 'target_step_id')` — `targetStep`

**Indexes**:
- `(step_id, code)` unique
- `(step_id, sort_order)` for ordered loading

---

### 1.5 `workflow_conditions` — reusable guards

**Eloquent model**: `HFlow\LaravelWorkflow\Models\WorkflowCondition`

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `uuid` | UUID UK | |
| `workflow_id` | BIGINT, null FK → `workflows.id` | null = global/reusable |
| `name` | VARCHAR | |
| `code` | VARCHAR | |
| `kind` | VARCHAR | `expression` \| `custom` \| `composite` (cast to `ConditionKind`) |
| `expression` | JSON, null | `{logic:"and|or", clauses:[{field,operator,value}], groups:[…]}` for `expression`/`composite` |
| `evaluator` | VARCHAR, null | FQCN for `custom` |

**Relationships**:
- `belongsTo(Workflow::class, 'workflow_id')` — `workflow` (nullable)
- `hasMany(WorkflowStepAction::class, 'guard_condition_id')` — `guardedActions`
- `hasMany(WorkflowTransition::class, 'condition_id')` — `guardedTransitions`

**Expression shape** (typed in PHP as `ExpressionCondition` value object):

```json
{
  "logic": "and",
  "clauses": [
    {"field": "subject.amount", "operator": ">", "value": 1000},
    {"field": "context.requester_role", "operator": "==", "value": "manager"}
  ],
  "groups": [
    {
      "logic": "or",
      "clauses": [
        {"field": "subject.priority", "operator": "==", "value": "high"},
        {"field": "user.id", "operator": "in", "value": [1, 2, 3]}
      ]
    }
  ]
}
```

- `field` references either `subject.<attribute>`, `context.<key>`, `user.<attribute>`, or `instance.<attribute>`.
- `operator` is one of: `==`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`, `contains`, `starts_with`, `ends_with`, `is_null`, `is_not_null`, `is_empty`, `is_not_empty`.
- `value` is a JSON scalar or array; type coercion is performed at evaluation time.

**Indexes**:
- `(workflow_id, code)` (non-unique; conditions may be global)
- `(kind)` for fast kind-based dispatch

---

### 1.6 `workflow_transitions` — directed edges + routing

**Eloquent model**: `HFlow\LaravelWorkflow\Models\WorkflowTransition`

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `uuid` | UUID UK | |
| `workflow_id` | BIGINT FK → `workflows.id` | |
| `from_step_id` | BIGINT, null FK → `workflow_steps.id` | null = from start |
| `to_step_id` | BIGINT, null FK → `workflow_steps.id` | null = to end |
| `action_id` | BIGINT, null FK → `workflow_step_actions.id` | triggering action |
| `condition_id` | BIGINT, null FK → `workflow_conditions.id` | guard |
| `type` | VARCHAR | `forward` \| `skip` \| `return` \| `conditional` \| `automatic` (cast to `TransitionType`) |
| `priority` | INT | higher first for auto/conditional |

**Relationships**:
- `belongsTo(Workflow::class, 'workflow_id')` — `workflow`
- `belongsTo(WorkflowStep::class, 'from_step_id')` — `fromStep` (nullable)
- `belongsTo(WorkflowStep::class, 'to_step_id')` — `toStep` (nullable)
- `belongsTo(WorkflowStepAction::class, 'action_id')` — `action` (nullable)
- `belongsTo(WorkflowCondition::class, 'condition_id')` — `condition` (nullable)

**Indexes**:
- `(workflow_id, from_step_id, priority desc)` for fast "next transitions from step X"
- `(action_id)` for fast "transitions triggered by this action"

---

## 2. Runtime tables (execution-time)

### 2.1 `workflow_instances` — a running workflow bound to a subject

**Eloquent model**: `HFlow\LaravelWorkflow\Models\WorkflowInstance`  
**State machine**: `InstanceStateMachine` (states `pending | in_progress | on_hold | completed | cancelled | rejected | failed`)

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `uuid` | UUID UK | |
| `tenant_id` | BIGINT, null | |
| `workflow_id` | BIGINT FK → `workflows.id` | |
| `workflow_version` | INT | pinned at start |
| `subject_type` | VARCHAR | polymorphic subject (`workflowable`) |
| `subject_id` | BIGINT | polymorphic subject |
| `current_step_id` | BIGINT, null FK → `workflow_steps.id` | convenience pointer; authoritative current = active step instances |
| `status` | VARCHAR | cast to `InstanceStatus` |
| `context` | JSON, null | runtime data bag |
| `initiated_by` | BIGINT, null | → host users |
| `started_at` | TIMESTAMPTZ, null | |
| `completed_at` | TIMESTAMPTZ, null | |

**Relationships**:
- `belongsTo(Workflow::class, 'workflow_id')` — `workflow`
- `belongsTo(WorkflowStep::class, 'current_step_id')` — `currentStep` (nullable)
- `morphTo('subject', 'subject_type', 'subject_id')` — `workflowable` (the host model record)
- `hasMany(WorkflowStepInstance::class, 'workflow_instance_id')` — `stepInstances`
- `hasMany(WorkflowHistory::class, 'workflow_instance_id')` — `histories`

**State transitions** (`InstanceStateMachine`):
- `pending → in_progress` (start; pins version, writes `started`)
- `in_progress → on_hold` (hold)
- `on_hold → in_progress` (resume)
- `in_progress → completed` (reach end step)
- `in_progress → rejected` (reject action routes to a rejected end)
- `in_progress → failed` (automated step fails)
- `failed → in_progress` (retry; re-enters failed step as a new step instance)
- `in_progress → cancelled`, `on_hold → cancelled` (cancel; remaining active steps closed)

Terminal: `completed`, `rejected`, `cancelled`. `failed` is recoverable (retry) — not terminal.

**Indexes**:
- `(subject_type, subject_id)` for "all instances on this record"
- `(workflow_id, status)` for "all in-progress instances of this workflow"
- `(status)` for global lookups (e.g. cron / dashboards)
- `(tenant_id)` for multi-tenant scoping

---

### 2.2 `workflow_step_instances` — per-step runtime record

**Eloquent model**: `HFlow\LaravelWorkflow\Models\WorkflowStepInstance`  
**State machine**: `StepInstanceStateMachine` (states `pending | active | completed | skipped | returned | rejected | failed`)

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `uuid` | UUID UK | |
| `workflow_instance_id` | BIGINT FK → `workflow_instances.id` | |
| `step_id` | BIGINT FK → `workflow_steps.id` | |
| `status` | VARCHAR | cast to `StepInstanceStatus` |
| `entered_at` | TIMESTAMPTZ, null | |
| `completed_at` | TIMESTAMPTZ, null | |
| `due_at` | TIMESTAMPTZ, null | from `sla_seconds` |
| `acted_by` | BIGINT, null | → host users |
| `action_taken` | VARCHAR, null | action `code` used to leave |
| `comment` | TEXT, null | |
| `data` | JSON, null | step-local data / handler output |

**Relationships**:
- `belongsTo(WorkflowInstance::class, 'workflow_instance_id')` — `instance`
- `belongsTo(WorkflowStep::class, 'step_id')` — `step`
- `hasMany(WorkflowAssignment::class, 'step_instance_id')` — `assignments`
- `hasMany(WorkflowHistory::class, 'step_instance_id')` — `histories`

**State transitions** (`StepInstanceStateMachine`):
- `pending → active` (enter; sets `entered_at`, computes `due_at`)
- `active → completed` (action completes step; `match_mode = all` requires quorum)
- `active → skipped` (skip; step `is_skippable` + guard)
- `active → returned` (return; step `is_returnable` + guard; target re-entered as a new step instance)
- `active → rejected` (reject action)
- `active → failed` (automated handler error)
- `failed → active` (retry; spawns a fresh step instance)

**Indexes**:
- `(workflow_instance_id, status)` for "all active steps of this instance"

---

### 2.3 `workflow_assignments` — runtime task assignments

**Eloquent model**: `HFlow\LaravelWorkflow\Models\WorkflowAssignment`  
**State machine**: `AssignmentStateMachine` (states `pending | acted | reassigned | expired`)

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `uuid` | UUID UK | |
| `step_instance_id` | BIGINT FK → `workflow_step_instances.id` | |
| `assignee_id` | BIGINT | → host users |
| `status` | VARCHAR | cast to `AssignmentStatus` |
| `assigned_at` | TIMESTAMPTZ, null | |
| `acted_at` | TIMESTAMPTZ, null | |

**Relationships**:
- `belongsTo(WorkflowStepInstance::class, 'step_instance_id')` — `stepInstance`
- `morphTo`-like: `assignee_id` references host `users.id` (no Eloquent `user()` relation by default — the host provides the user model; the package only stores the FK)

**State transitions** (`AssignmentStateMachine`):
- `pending → acted` (assignee performs action)
- `pending → reassigned` (reassign to another user)
- `pending → expired` (SLA `due_at` passed)

Quorum rules:
- `match_mode = any`: first `acted` assignment completes the step; remaining `pending` assignments are marked `expired`.
- `match_mode = all`: step completes only after every assignment is `acted`.

**Indexes**:
- `(assignee_id, status)` for the task-inbox query ("give me all my pending tasks")
- `(step_instance_id, status)` for "all pending assignments on this step"

---

### 2.4 `workflow_histories` — immutable audit trail (append-only)

**Eloquent model**: `HFlow\LaravelWorkflow\Models\WorkflowHistory`  
**No state machine** (append-only — no updates, no soft delete).  
**Convention exception**: this table has `created_at` only. **No `updated_at`, no `is_deleted`, no `deleted_at` columns.**

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `uuid` | UUID UK | |
| `workflow_instance_id` | BIGINT FK → `workflow_instances.id` | |
| `step_instance_id` | BIGINT, null FK → `workflow_step_instances.id` | |
| `from_step_id` | BIGINT, null FK → `workflow_steps.id` | |
| `to_step_id` | BIGINT, null FK → `workflow_steps.id` | |
| `action_code` | VARCHAR, null | |
| `event` | VARCHAR | cast to `HistoryEvent` |
| `actor_id` | BIGINT, null | → host users; null = system |
| `actor_type` | VARCHAR | `user` \| `system` (cast to `ActorType`) |
| `comment` | TEXT, null | |
| `metadata` | JSON, null | changes / payload snapshot |
| `performed_at` | TIMESTAMPTZ | |
| `created_at` | TIMESTAMPTZ | append-only timestamp |

**Invariants** (enforced by tests and by an ArchTest rule):
- The model's `$timestamps` property is `false` (or `public const UPDATED_AT = null`).
- The model **does not** use the `SoftDeletes` trait.
- The model's `update()` / `delete()` / `forceDelete()` methods are explicitly disabled in the `HistoryRecorder` (one direct `INSERT` per event; no `save()` round-trips, no Eloquent events that could mutate state).
- The model's `save()` may still be used internally for the initial INSERT, but the **only public way** to write to this table is `HistoryRecorder::record(...)`, which performs a direct `INSERT` and never updates.

**Indexes**:
- `(workflow_instance_id, performed_at)` for the activity feed (chronological)
- `(event)` for filtering by event type
- `(actor_id, performed_at)` for "all events performed by user X"

---

## 3. Enumerated values (PHP enums in `src/States/`)

| Enum | Allowed values | Field |
|---|---|---|
| `WorkflowType` | `automation`, `approval`, `generic` | `workflows.type` |
| `WorkflowStatus` | `draft`, `active`, `archived` | `workflows.status` |
| `StepType` | `start`, `task`, `approval`, `automated`, `gateway`, `end` | `workflow_steps.type` |
| `AuthorizationMode` | `public`, `roles`, `permissions`, `users`, `custom` | `workflow_steps.authorization_mode` |
| `MatchMode` | `any`, `all` | `workflow_steps.match_mode` |
| `AssigneeType` | `role`, `permission`, `user`, `public`, `custom` | `workflow_step_assignees.assignee_type` |
| `ActionType` | `submit`, `approve`, `reject`, `skip`, `return`, `complete`, `cancel`, `custom` | `workflow_step_actions.type` |
| `ActionAvailabilityMode` | `general`, `conditional`, `custom` | `workflow_step_actions.availability_mode` |
| `ConditionKind` | `expression`, `custom`, `composite` | `workflow_conditions.kind` |
| `TransitionType` | `forward`, `skip`, `return`, `conditional`, `automatic` | `workflow_transitions.type` |
| `InstanceStatus` | `pending`, `in_progress`, `on_hold`, `completed`, `cancelled`, `rejected`, `failed` | `workflow_instances.status` |
| `StepInstanceStatus` | `pending`, `active`, `completed`, `skipped`, `returned`, `rejected`, `failed` | `workflow_step_instances.status` |
| `AssignmentStatus` | `pending`, `acted`, `reassigned`, `expired` | `workflow_assignments.status` |
| `HistoryEvent` | `started`, `step_entered`, `step_completed`, `action_performed`, `skipped`, `returned`, `completed`, `cancelled`, `comment_added`, `error` | `workflow_histories.event` |
| `ActorType` | `user`, `system` | `workflow_histories.actor_type` |

All enums are `final` PHP 8.1 backed enums (`: string`). Their string values are the exact strings stored in the database. The package never creates `lookup_*` tables and never uses database `ENUM` types.

---

## 4. Migration strategy

- **One combined migration** at `database/migrations/2024_01_01_000000_create_workflow_table.php` (the stub is already at `database/migrations/create_workflow_table.php.stub`; the generated file replaces it).
- The migration is registered via `Package::hasMigration('create_workflow_table')` on the service provider.
- The migration reads `config('workflow.table_prefix')` at runtime, so a host with a custom prefix gets the correct table names without editing the file.
- The migration creates the ten tables in dependency order: definition tables first, then runtime tables, then `workflow_histories` last (the audit table is the keystone of the engine and gets the most defensive column definitions).
- Indexes are declared inline on the column where natural, and as `table->index([...])` for composite indexes.
- The migration is **idempotent** in the test suite: the workbench testbed drops all tables and re-runs it on each test class.

---

## 5. Eloquent model conventions

- All models are `final` classes extending `Illuminate\Database\Eloquent\Model`.
- All models use the `HasUuids` concern (or a local `HasUuid` trait) to generate `uuid` v4 on create.
- All models have `casts()` declared for every enum field (e.g. `'type' => WorkflowType::class`).
- All models declare `fillable` (or use `guarded = []` only for the four state-machine models where the entire row is internal and never mass-assigned from user input).
- All models that are not `workflow_histories` use the `SoftDeletes` trait and the `InteractsWithUuids` / `HasAuditColumns` local concerns for the audit columns.
- All `workflow_*_id` foreign keys are declared with `->constrained('workflow_xxx')` so the package can change the table prefix without rewriting every FK.
- Polymorphic columns (`subject_type`, `subject_id`) are declared with the host's `morphMap()` registration; the package does not impose a specific map.

---

## 6. Relationship summary

```
workflows 1—N workflow_steps, workflow_transitions, workflow_conditions (optional), workflow_instances
workflows N—1 workflow_steps (via start_step_id)
workflow_steps 1—N workflow_step_assignees, workflow_step_actions, workflow_step_instances
workflow_steps referenced twice by workflow_transitions (from_step_id, to_step_id) and by workflow_step_actions.target_step_id
workflow_conditions referenced by workflow_transitions.condition_id and workflow_step_actions.guard_condition_id
workflow_instances 1—N workflow_step_instances, workflow_histories; N—1 workflow_steps (via current_step_id)
workflow_step_instances 1—N workflow_assignments, workflow_histories
polymorphic: workflow_instances.(subject_type, subject_id) → any host model
```

---

## 7. Validation rules (model-level)

These are enforced by `Saving` model events or by the engine's own validation on the way in:

- `Workflow.activate()` is the only path to `status = active`; the model refuses direct writes to `status` outside the state machine.
- `Workflow.code` is unique per `(tenant_id, code, deleted_at)`.
- `WorkflowStep.code` is unique per `(workflow_id, code, deleted_at)`.
- `WorkflowStepAction.code` is unique per `(step_id, code, deleted_at)`.
- Exactly one `WorkflowStep` per workflow has `type = start`; at least one has `type = end` (enforced at activation time, not at save time — see `WorkflowStateMachine::canActivate()`).
- `WorkflowHistory` rows are write-once: no `update()`, no `delete()` (enforced by `HistoryRecorder`'s direct-insert pattern and by an ArchTest).
