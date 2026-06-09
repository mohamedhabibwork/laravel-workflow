# Data Model: Laravel Workflow Package

## Overview

Based on the ERD and BRD, the following entities will be implemented as Eloquent models. All models will use `BIGINT` primary keys, `UUID` public keys, and include `created_at`, `updated_at`, `deleted_at`, and audit columns (`created_by`, `updated_by`, `deleted_by`) unless otherwise specified.

## Definition Entities (Design-Time)

### 1. Workflow (`workflows`)
- **Fields**: `name`, `code` (unique), `type` (Enum), `subject_type` (nullable), `version` (int), `is_current_version` (bool), `status` (Enum), `start_step_id` (FK), `require_explicit_transitions` (bool), `config` (json), `tenant_id` (nullable).
- **Relationships**: Has many `WorkflowStep`, `WorkflowTransition`, `WorkflowCondition`, `WorkflowInstance`.

### 2. WorkflowStep (`workflow_steps`)
- **Fields**: `workflow_id` (FK), `name`, `code`, `type` (Enum), `position` (int), `authorization_mode` (Enum), `match_mode` (Enum), `custom_authorizer` (string), `handler` (string), `is_skippable` (bool), `is_returnable` (bool), `sla_seconds` (int).
- **Relationships**: Has many `WorkflowStepAssignee`, `WorkflowStepAction`, `WorkflowStepInstance`.

### 3. WorkflowStepAssignee (`workflow_step_assignees`)
- **Fields**: `step_id` (FK), `assignee_type` (Enum), `assignee_value` (string), `custom_resolver` (string), `sort_order` (int).

### 4. WorkflowStepAction (`workflow_step_actions`)
- **Fields**: `step_id` (FK), `name`, `code`, `label`, `type` (Enum), `availability_mode` (Enum), `guard_condition_id` (FK, nullable), `guard_class` (string), `target_step_id` (FK, nullable), `requires_comment` (bool), `handler` (string).

### 5. WorkflowCondition (`workflow_conditions`)
- **Fields**: `workflow_id` (FK, nullable), `name`, `code`, `kind` (Enum), `expression` (json), `evaluator` (string).

### 6. WorkflowTransition (`workflow_transitions`)
- **Fields**: `workflow_id` (FK), `from_step_id` (FK, nullable), `to_step_id` (FK, nullable), `action_id` (FK, nullable), `condition_id` (FK, nullable), `type` (Enum), `priority` (int).

## Runtime Entities (Execution-Time)

### 7. WorkflowInstance (`workflow_instances`)
- **Fields**: `workflow_id` (FK), `workflow_version` (int), `subject_type` (string), `subject_id` (BIGINT), `current_step_id` (FK, nullable), `status` (Enum), `context` (json), `initiated_by` (BIGINT, nullable), `started_at`, `completed_at`, `tenant_id` (nullable).
- **Polymorphic**: `workflowable` (subject).

### 8. WorkflowStepInstance (`workflow_step_instances`)
- **Fields**: `workflow_instance_id` (FK), `step_id` (FK), `status` (Enum), `entered_at`, `completed_at`, `due_at`, `acted_by` (BIGINT, nullable), `action_taken` (string), `comment` (text), `data` (json).

### 9. WorkflowAssignment (`workflow_assignments`)
- **Fields**: `step_instance_id` (FK), `assignee_id` (BIGINT), `status` (Enum), `assigned_at`, `acted_at`.

### 10. WorkflowHistory (`workflow_histories`)
- **Fields**: `workflow_instance_id` (FK), `step_instance_id` (FK, nullable), `from_step_id` (FK, nullable), `to_step_id` (FK, nullable), `action_code` (string), `event` (Enum), `actor_id` (BIGINT, nullable), `actor_type` (Enum), `comment` (text), `metadata` (json), `performed_at`.
- **Note**: Append-only, no `updated_at`, no soft deletes.

## Enums (PHP-level)

- `WorkflowType`: `automation`, `approval`, `generic`
- `WorkflowStatus`: `draft`, `active`, `archived`
- `StepType`: `start`, `task`, `approval`, `automated`, `gateway`, `end`
- `AuthorizationMode`: `public`, `roles`, `permissions`, `users`, `custom`
- `MatchMode`: `any`, `all`
- `AssigneeType`: `role`, `permission`, `user`, `public`, `custom`
- `ActionType`: `submit`, `approve`, `reject`, `skip`, `return`, `complete`, `cancel`, `custom`
- `AvailabilityMode`: `general`, `conditional`, `custom`
- `ConditionKind`: `expression`, `custom`, `composite`
- `TransitionType`: `forward`, `skip`, `return`, `conditional`, `automatic`
- `InstanceStatus`: `pending`, `in_progress`, `on_hold`, `completed`, `cancelled`, `rejected`, `failed`
- `StepStatus`: `pending`, `active`, `completed`, `skipped`, `returned`, `rejected`, `failed`
- `AssignmentStatus`: `pending`, `acted`, `reassigned`, `expired`
- `HistoryEvent`: `started`, `step_entered`, `step_completed`, `action_performed`, `skipped`, `returned`, `completed`, `cancelled`, `comment_added`, `error`
- `ActorType`: `user`, `system`
