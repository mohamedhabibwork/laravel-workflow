# Laravel Workflow Engine — Documentation Suite

A reusable, generic workflow engine package for Laravel. One model serves three workflow families:

- **`automation`** — system-driven pipelines (steps auto-execute and chain).
- **`approval`** — human-driven approval flows (steps wait for an authorized actor).
- **`generic`** — free-form state machines mixing manual and automated steps.

## Documents

| File | Contents |
|---|---|
| [`BRD.md`](./BRD.md) | Business requirements & rules only — no code. |
| [`ERD.md`](./ERD.md) | Entities, columns, types, keys, relationships + Mermaid ER diagram. |
| [`STATE_MACHINES.md`](./STATE_MACHINES.md) | Complete lifecycle for every status field + enumerated value reference. |

## Capabilities at a glance

- Attach a workflow to **any** host model (polymorphic `workflowable` subject).
- Per-step authorization: **public / roles / permissions / specific users / custom logic**.
- **Available-actions resolution** per user — general or custom guard (deterministic, server-validated).
- **Get current step**, **skip**, and **return** steps gated by conditions (general expression or custom evaluator).
- Reusable **conditions** (expression / custom / composite) driving transitions, action availability, skip & return.
- **Activities & history** — append-only immutable audit trail; the activity feed is derived from it.
- Workflow **versioning** — live instances keep running on the version they started with.
- Optional **multi-tenancy** hook (`tenant_id`, host-driven scope).

## Design constraints honored

- **No `lookup_types` / `lookups` tables and no database `ENUM`** — all type/status fields are `VARCHAR` backed by PHP enums/constants (see the value reference in `STATE_MACHINES.md`).
- Schema conventions: `BIGINT` PK, `UUID` UK, `TIMESTAMPTZ` timestamps, soft delete (`is_deleted` + `deleted_at`), full audit columns (`created_by` / `updated_by` / `deleted_by`).
- Package-aware: user FKs are nullable `BIGINT` → host `users.id`; table prefix is configurable (`workflow_` default).
- BRD contains no code; ERD is a separate file.

## Table map

**Definitions (design-time):** `workflows`, `workflow_steps`, `workflow_step_assignees`, `workflow_step_actions`, `workflow_conditions`, `workflow_transitions`.

**Runtime (execution-time):** `workflow_instances`, `workflow_step_instances`, `workflow_assignments`, `workflow_histories` *(append-only)*.
