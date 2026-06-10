# Feature Specification: Laravel Workflow Package

**Feature Branch**: `003-laravel-workflow-package`  
**Created**: 2026-06-09  
**Status**: Draft  
**Input**: User description: "workflow package using @laravel-workflow-docs"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Workflow Design & Activation (Priority: P1)

As a Workflow Designer, I want to define a versioned blueprint with steps, transitions, and conditions so that the system can guide business processes.

**Why this priority**: This is the foundation of the package. Without definitions, no instances can exist.

**Independent Test**: Can be tested by creating a workflow with one start and one end step, defining a transition between them, and successfully activating it.

**Acceptance Scenarios**:

1. **Given** a new workflow is being drafted, **When** I add exactly one start step and at least one end step, **Then** I can change the status from `draft` to `active`.
2. **Given** an `active` workflow, **When** I attempt to modify its structure, **Then** the system requires me to create a new version to preserve existing instances.

---

### User Story 2 - Human-Driven Approval (Priority: P2)

As a Participant, I want to see available actions for a record assigned to me and perform an action to advance the process.

**Why this priority**: Core functionality for `approval` and `generic` workflow types.

**Independent Test**: Can be tested by starting an instance on an `approval` workflow, querying available actions for an authorized user, and performing an action that advances the step.

**Acceptance Scenarios**:

1. **Given** I am an authorized actor for the current step, **When** I request available actions, **Then** I receive a deterministic list of actions gated by conditions and custom logic.
2. **Given** an action requires a comment, **When** I perform the action without a comment, **Then** the engine rejects the advancement with a validation error.

---

### User Story 3 - System-Driven Automation (Priority: P3)

As a Host Application, I want the engine to automatically execute steps and transitions in a pipeline without human intervention.

**Why this priority**: Essential for `automation` pipelines and efficiency in mixed workflows.

**Independent Test**: Can be tested by entering an `automated` step and verifying the engine executes the handler and advances to the next step immediately.

**Acceptance Scenarios**:

1. **Given** the instance enters an `automated` step, **When** the entry event fires, **Then** the system executes the step handler and evaluates the first passing automatic transition.
2. **Given** an automated step handler fails, **When** the error occurs, **Then** the instance status is set to `failed` and remains recoverable via a retry.

---

### Edge Cases

- **Concurrent Actions**: How does the system handle two users performing an action on the same step instance simultaneously? (First valid action wins; server-side re-validation is mandatory).
- **Cyclic Returns**: What happens if a workflow allows returning to a step that eventually returns back? (Engine supports this via append-only history and new step instances; infinite loops are handled by host-level monitoring or SLA timeouts).
- **Missing Fallback**: How does the system handle a non-end step with no matching transitions and no sequential fallback? (Engine should halt or throw a routing exception if `require_explicit_transitions` is true).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST support three workflow families (`automation`, `approval`, `generic`) through a single unified engine.
- **FR-002**: Workflows MUST be versioned, ensuring live instances remain on the version they started with even if the blueprint changes.
- **FR-003**: System MUST provide deterministic available-action resolution based on authorization modes (`public`, `roles`, `permissions`, `users`, `custom`).
- **FR-004**: System MUST support polymorphic `workflowable` subjects, allowing the engine to be attached to any host model.
- **FR-005**: System MUST maintain an immutable, append-only history log of all events (`started`, `step_entered`, `action_performed`, etc.).
- **FR-006**: System MUST support skip and return transitions, gated by step-level flags and guard conditions.
- **FR-007**: System MUST support multi-tenancy via a configurable `tenant_id` on all primary tables.
- **FR-008**: System MUST NOT use database `ENUM` or lookup tables for status/type fields, utilizing application-level PHP enums instead.

### Key Entities *(include if feature involves data)*

- **Workflow**: The versioned blueprint containing steps, transitions, and conditions.
- **Workflow Step**: A node in the process (start, task, approval, automated, gateway, end).
- **Workflow Action**: A named operation (approve, reject, etc.) available at a step.
- **Workflow Instance**: The runtime execution bound to a specific subject record.
- **Step Instance**: The record of a single step's lifecycle within an instance.
- **History**: The immutable audit log of all events and transitions.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Available actions for any user are resolved in under 100ms for workflows with up to 50 steps.
- **SC-002**: 100% of state transitions are reflected accurately in the immutable history log.
- **SC-003**: System supports at least 10,000 concurrent active instances without performance degradation on standard hardware.
- **SC-004**: Developers can integrate the package into a host application and attach a workflow to a model in under 30 minutes.

## Assumptions

- **Environment**: Target environment is Laravel 11+ with PHP 8.2+.
- **Database**: Host application uses a relational database (PostgreSQL/MySQL) supporting JSON columns.
- **Identity**: Host application manages `users` and provides user IDs as `BIGINT`.
- **Authorization**: Host provides the underlying role/permission resolution (e.g., via Spatie Permissions or Laravel Gates).
- **Storage**: Table prefix is configurable to avoid collisions with host tables.
