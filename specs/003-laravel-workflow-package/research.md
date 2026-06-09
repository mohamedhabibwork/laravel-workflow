# Research: Laravel Workflow Package

## Decision: Core Architecture & Pattern Selection

- **Condition Evaluation**: Use a dedicated `ConditionEvaluator` service that handles both `expression` (structured JSON) and `custom` (class-based) guards. Expression evaluation will use a recursive pattern for composite logic (AND/OR).
- **Action Resolution**: Implement a deterministic `ActionResolver` that follows the BR-X-06 to BR-X-10 priority: Eligibility -> Gathering -> Availability.
- **State Transitions**: Use an Observer or Service pattern to handle the side-effects of transitions (history logging, step entry, automated handler execution).
- **Multi-Tenancy**: Leverage a Global Scope on all workflow-related models that respects a configured `tenant_id` column, similar to common Laravel tenancy packages.

## Rationale

- **Condition Evaluator**: Decoupling logic from the model ensures testability and allows host applications to easily extend the engine with custom rules.
- **Action Resolver**: Deterministic resolution is critical for UI consistency (available actions must be the same every time the same state is queried).
- **State Transitions**: Centralizing advanced routing logic (sequential fallback vs explicit transitions) prevents duplication and ensures the history log is always consistent.

## Alternatives Considered

- **Alternative**: Using a Finite State Machine (FSM) library like `yohang/finite` or `winzou/state-machine`.
  - **Rejected Because**: These libraries are often too rigid for the dynamic, database-driven workflows required by this package (where steps/transitions are defined at runtime by users, not just in code).
- **Alternative**: Database ENUMs for status fields.
  - **Rejected Because**: Violates the project constitution and the "No database ENUM" rule in the BRD.
- **Alternative**: Storing history as a simple JSON blob on the instance.
  - **Rejected Because**: Fails the audit/immutable record requirement; a separate append-only table is necessary for high-integrity audit trails.

## Technical Details Research

- **JSON Expressions**: Will follow a standard `{ "field": "...", "operator": "...", "value": "..." }` structure, supporting operators like `eq`, `neq`, `gt`, `lt`, `in`, `contains`.
- **Custom Handlers**: Will resolve via `app()->make($className)` to support dependency injection from the host application.
- **Concurrency**: Use database transactions (`DB::transaction`) during action performance to ensure atomicity between closing one step instance and opening the next.
