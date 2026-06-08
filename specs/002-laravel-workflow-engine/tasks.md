---
description: "Task list for Laravel Workflow Engine (P1–P3 user stories, 10 tables, 15 enums, 6 host contracts, 1 facade, 1 service, 4 commands)"
---

# Tasks: Laravel Workflow Engine (Generic, Reusable Package)

**Input**: Design documents from `/specs/002-laravel-workflow-engine/`
**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md, contracts/

**Tests**: Tests are included — the spec's SC-001..SC-012 require assertion of behavior (e.g. SC-003 "100% of state-changing operations produce a history entry", SC-007 "skip/return preserve history", SC-011 "first-valid-action-wins") and the research.md plan includes Pest 4 + Pest Arch + Testbench layers.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (US1..US7)
- Include exact file paths in descriptions

## Path Conventions

- **Single Composer project** (Laravel package): source under `src/`, tests under `tests/`, one combined migration under `database/migrations/`, factories under `database/factories/`, config under `config/`, CI under `.github/workflows/`.
- The package lives at the repository root. The host application is whichever Laravel 10–13 project installs the package.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization, dependency bumps, scaffold cleanup, CI scaffold.

- [x] T001 Bump `composer.json` dependency constraints in `/Users/habib/Herd/workflow/laravel-workflow/composer.json`: change `illuminate/contracts` from `^11.0||^12.0||^13.0` to `^10.0||^11.0||^12.0||^13.0` and broaden `orchestra/testbench` to `^11.0||^10.0||^9.0||^8.0` so Laravel 10 is testable
- [x] T002 Remove the `->hasViews()` call (and any view publishing tags) from `src/LaravelWorkflowServiceProvider.php` — the engine ships no UI
- [x] T003 [P] Add the matrix CI workflow at `.github/workflows/tests.yml` running Pest across Laravel {10, 11, 12, 13} × PHP {8.2, 8.3, 8.4} where supported
- [x] T004 [P] Create `.github/workflows/lint.yml` running Larastan level 5 + Pint on every push/PR
- [x] T005 [P] Clean placeholder directories in `src/` (keep `Commands/`, `Contracts/`, `Exceptions/`, `Facades/`, `QueryBuilder/`, `StateMachine/`, `States/`, `Tags/`; remove `ActivityRecorder/`, `ApproverResolver/`, `DataValidator/`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core infrastructure that MUST be complete before ANY user story can be implemented. Defines the entire data layer, the enum/exception vocabulary, the value objects, the service container wiring, and the architectural guards.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T006 Create the single combined migration at `database/migrations/2024_01_01_000000_create_workflow_table.php` (replacing `create_workflow_table.php.stub`) that reads `config('workflow.table_prefix')` at runtime and creates all 10 tables (`workflows`, `workflow_steps`, `workflow_step_assignees`, `workflow_step_actions`, `workflow_conditions`, `workflow_transitions`, `workflow_instances`, `workflow_step_instances`, `workflow_assignments`, `workflow_histories`) with the columns, types, FKs, and indexes defined in `data-model.md` §1–§2; `workflow_histories` must have only `created_at` (no `updated_at`, no `is_deleted`, no `deleted_at`)
- [x] T007 [P] Create the 15 backed PHP enums under `src/Enums/` (all `final` `string` enums): `WorkflowType`, `WorkflowStatus`, `StepType`, `AuthorizationMode`, `MatchMode`, `AssigneeType`, `ActionType`, `ActionAvailabilityMode`, `ConditionKind`, `TransitionType`, `InstanceStatus`, `StepInstanceStatus`, `AssignmentStatus`, `HistoryEvent`, `ActorType` — values match `data-model.md` §3
- [x] T008 [P] Create the 4 state machines under `src/StateMachine/` (each as a `final` class with a static `canTransition(from, to): bool` and a `states(): array` helper, no Eloquent dependency): `WorkflowStateMachine`, `InstanceStateMachine`, `StepInstanceStateMachine`, `AssignmentStateMachine` — transitions match `data-model.md` §1.1, §2.1, §2.2, §2.3
- [x] T009 [P] Create the 10 Eloquent models under `src/Models/` (all `final` classes extending `Illuminate\Database\Eloquent\Model`, all use `HasUuid` trait, all have enum casts, all FKs use `->constrained()` so the table prefix is respected): `Workflow`, `WorkflowStep`, `WorkflowStepAssignee`, `WorkflowStepAction`, `WorkflowCondition`, `WorkflowTransition`, `WorkflowInstance`, `WorkflowStepInstance`, `WorkflowAssignment`, `WorkflowHistory`
- [x] T010 [P] Create the 10 model factories under `database/factories/` (one per model, each with sane defaults using the enums; `WorkflowHistoryFactory` must default to a `system` actor and a `started` event)
- [x] T011 [P] Create the 4 Eloquent concerns under `src/Concerns/`: `HasUuid` (assigns `uuid` v4 on create), `HasAuditColumns` (manages `created_by`/`updated_by`/`deleted_by`), `BelongsToWorkflow` (helper to navigate from a runtime model to its workflow), `AppendOnlyHistory` (locks down the `WorkflowHistory` model: disables `UPDATED_AT`, no `SoftDeletes`, declares `$writeOnce = true` so ArchTest can grep it)
- [x] T012 Create the 12 custom exceptions under `src/Exceptions/` (all `final` classes extending `WorkflowException` which extends `RuntimeException`): `WorkflowException` (base), `InvalidWorkflowException`, `InvalidStateException`, `NotEligibleException`, `ActionNotAvailableException`, `CommentRequiredException`, `SkipNotAllowedException`, `ReturnNotAllowedException`, `TransitionNotFoundException`, `WorkflowTerminalException`, `WorkflowSubjectMismatchException`, `InvalidExpressionException`, `AutomationLoopGuardException`
- [x] T013 [P] Create the two value objects under `src/Actions/`: `Action` (readonly DTO with `code`, `name`, `label`, `type: ActionType`, `requiresComment: bool`, `targetStep: ?WorkflowStep`, `sortOrder: int`) and `ActionSet` (extends `Illuminate\Support\Collection<int, Action>`, adds `isEmpty()`, `forUser()`, `codes()` helpers, implements `IteratorAggregate` for ordered iteration)
- [x] T014 Create `config/workflow.php` (replacing the stub) with keys: `table_prefix` (default `workflow_`), `database_connection` (default `null`), `tenant` (`enabled` false, `column` `tenant_id`, `scope_resolver` null), `history` (`append_only` true — locked, validated at boot), `automation` (`max_chain_depth` 50)
- [x] T015 Register the service container in `src/LaravelWorkflowServiceProvider.php`: bind `HFlow\LaravelWorkflow\Contracts\WorkflowEngine` to `HFlow\LaravelWorkflow\Engines\WorkflowEngine` (singleton), register the `AppendOnlyHistory` invariant at boot, publish the migration + config via `Package::hasMigration('create_workflow_table')` + `Package::hasConfigFile()`, expose the `LaravelWorkflow` facade accessor
- [x] T016 [P] Create the `LaravelWorkflow` facade at `src/Facades/LaravelWorkflow.php` returning `WorkflowEngine::class` from `getFacadeAccessor()`
- [x] T017 Wire `tests/TestCase.php`: uncomment the `loadMigrationsFrom(...)` line and call `$this->artisan('migrate')->run()` in `setUp()` so every Pest test starts from a freshly migrated SQLite database; also `defineDatabaseMigrations()` to load the published migration under Testbench
- [x] T018 Expand `tests/ArchTest.php` with the rules: no `dd`/`dump`/`ray`/`var_dump` anywhere; all classes in `src/Models/` are `final`; all classes in `src/Enums/` are `final`; all interfaces in `src/Contracts/` are `interfaces` (not classes); `src/Engines/WorkflowEngine.php` does not call `DB::raw()` directly; `src/Models/WorkflowHistory.php` declares `AppendOnlyHistory` trait; `src/Models/WorkflowHistory.php` has no `use SoftDeletes`; `src/Models/WorkflowHistory.php` does not declare `UPDATED_AT` (or sets it to `null`); the public `WorkflowEngine` class exposes the 14 documented methods
- [x] T019 [P] Add `.gitattributes` rule marking `database/migrations/`, `database/factories/`, `config/`, and `src/` as `export-ignore=false` so they are published to Packagist (replace existing stub if any)

**Checkpoint**: Foundation ready — `php artisan migrate` in a Testbench workbench creates all 10 tables; the enums, models, exceptions, state machines, value objects, service container, facade, TestCase, and ArchTest guards are all in place. User story implementation can now begin in parallel.

---

## Phase 3: User Story 1 — Define and activate a workflow (Priority: P1) 🎯 MVP

**Goal**: A workflow designer can create a versioned workflow with steps/actions/transitions, activate it, refuse activation of malformed shapes, and create new versions without mutating the in-use one.

**Independent Test**: A designer creates a workflow with one `start` step and one `end` step, activates it, and confirms it appears in the `active` list — without any instance being started. Then a second version is created while instances exist, and live instances continue on v1.

### Tests for User Story 1

- [x] T020 [P] [US1] Contract test at `tests/Contract/WorkflowEngineContractTest.php` asserting the `WorkflowEngine` exposes `createNewVersion(Workflow $workflow, array $overrides = []): Workflow`, `activate(Workflow $workflow): Workflow`, `versions(Workflow|string $workflow): Collection` with the exact signatures from `contracts/workflow-engine.md` §2.12–§2.14
- [x] T021 [P] [US1] Integration test at `tests/Integration/DefineActivateTest.php`: (a) define a workflow with one `start`, one `task`, one `end` step + 2 transitions, save as `draft`, refuse `start()` on it; (b) activate succeeds only with exactly one start + ≥1 end; (c) activate rejects 0 starts, 2 starts, 0 ends; (d) `createNewVersion` clones steps/transitions/conditions/actions/assignees, increments `version`, leaves `is_current_version = false`, leaves live instances untouched

### Implementation for User Story 1

- [x] T022 [US1] Implement `createNewVersion(Workflow $workflow, array $overrides = []): Workflow` on the engine — clone the workflow, deep-clone its `steps` (with their `assignees` and `actions`), `transitions`, and `conditions`; bump `version`, set `status = draft`, `is_current_version = false`; run inside a DB transaction; do not touch any `WorkflowInstance` rows
- [x] T023 [US1] Implement `activate(Workflow $workflow): Workflow` on the engine — assert exactly one `start` step and ≥1 `end` step (else throw `InvalidWorkflowException`), assert status is `draft` (else throw `InvalidStateException`), flip this workflow's `is_current_version` to true and the previous active version's to false (scoped to `(tenant_id, code)`), set `status = active`, run inside a DB transaction
- [x] T024 [US1] Implement `versions(Workflow|string $workflow): Collection` on the engine — accept either a `Workflow` model or a `code` string, return `Collection<Workflow>` ordered by `version desc`, scoped by tenant if tenancy is enabled
- [x] T025 [P] [US1] Unit test at `tests/Unit/StateMachines/WorkflowStateMachineTest.php` asserting all valid + invalid transitions in `WorkflowStateMachine` (draft→active, active→archived, archived→active, draft→archived, refuse all others)

**Checkpoint**: US1 fully functional and testable independently. A host developer can define, activate, and version a workflow without any user participating.

---

## Phase 4: User Story 2 — Start a workflow instance on a host model record (Priority: P1)

**Goal**: A host binds an `active` workflow to a host model record, starts a runtime instance, pins the workflow version, and reads back the current step.

**Independent Test**: A developer can take any host model record, start a workflow instance on it, and read back the current step — without ever performing an action. A `started` history entry is appended with the initiator as the actor.

### Tests for User Story 2

- [x] T026 [P] [US2] Contract test additions at `tests/Contract/WorkflowEngineContractTest.php` asserting `start(Workflow|string, Model, array = [], ?User = null): WorkflowInstance` and `currentStep(WorkflowInstance): WorkflowStepInstance|Collection` match the signatures in `contracts/workflow-engine.md` §2.1, §2.2
- [x] T027 [P] [US2] Integration test at `tests/Integration/StartInstanceTest.php`: (a) `start` on a host `Order` returns a `WorkflowInstance` with `status = in_progress`, `workflow_version` pinned, `workflowable` morphTo resolves to the Order; (b) `start` on a non-`active` workflow throws `InvalidWorkflowException`; (c) `start` on a subject whose class is not the workflow's `subject_type` throws `WorkflowSubjectMismatchException`; (d) `currentStep` returns the `start` step instance with `entered_at` set and `due_at` computed from `sla_seconds`; (e) a `started` history entry is appended with the initiator as the actor (or `actor_type = system` when null)

### Implementation for User Story 2

- [x] T028 [US2] Implement `HistoryRecorder` at `src/Engines/HistoryRecorder.php` — single public method `record(array $payload): WorkflowHistory` that performs a direct `INSERT` into `workflow_histories` via the query builder (bypassing Eloquent's `save()`) and dispatches a `WorkflowHistoryRecorded` Laravel event after the insert; no other write path is allowed; payload keys: `workflow_instance_id`, `step_instance_id`, `from_step_id`, `to_step_id`, `action_code`, `event`, `actor_id`, `actor_type`, `comment`, `metadata`, `performed_at`
- [x] T029 [US2] Implement `start(Workflow|string, Model, array = [], ?User = null): WorkflowInstance` on the engine — resolve the workflow (string → current version), reject non-`active` workflows, reject subject-class mismatches, pin `workflow_version`, insert a `workflow_instances` row (status `in_progress`, `started_at = now()`), insert a `workflow_step_instances` row for the start step (status `active`, `entered_at = now()`, `due_at` computed from `sla_seconds`), set `workflow_instances.current_step_id`, append a `started` history entry via `HistoryRecorder`, run inside a DB transaction
- [x] T030 [US2] Implement `currentStep(WorkflowInstance $instance): WorkflowStepInstance|Collection` on the engine — return the single active step instance, or a Collection of all active step instances when more than one exists (parallel branches from a `gateway` step); eager-load the `step` relation
- [x] T031 [P] [US2] Create the `WorkflowHistoryRecorded` event at `src/Observability/Events/WorkflowHistoryRecorded.php` (readonly, carries the `WorkflowHistory` row and a timestamp); the event has no listeners in the package itself — hosts subscribe via standard Laravel event listeners

**Checkpoint**: US1 + US2 fully functional. A host can define, activate, and start a workflow on a host model, then read back its current step — no actions yet, but the audit trail begins.

---

## Phase 5: User Story 3 — Resolve available actions and perform one to advance (Priority: P1)

**Goal**: A participant can list the actions they may perform right now, and perform one of them so the workflow advances. Server-side re-validation on every perform. First-valid-action-wins for `match_mode = any`.

**Independent Test**: For any user and any instance, the engine returns the deterministic, eligibility+availability-filtered action set; performing one advances the instance and appends a history entry; ineligible users get an empty set; `requires_comment` is enforced; two simultaneous actions on a `match_mode = any` step result in exactly one accept and the others `expired`.

### Tests for User Story 3

- [x] T032 [P] [US3] Contract test additions at `tests/Contract/WorkflowEngineContractTest.php` asserting `availableActions(WorkflowInstance, ?User = null): ActionSet` and `perform(WorkflowInstance, string, ?User = null, ?array = null): WorkflowInstance` match `contracts/workflow-engine.md` §2.3, §2.4
- [x] T033 [P] [US3] Integration test at `tests/Integration/AvailableActionsTest.php`: (a) on a `roles` step with the role held by the user, `availableActions` returns the action set; (b) on the same step with a user not holding the role, the set is empty; (c) on a `conditional` action whose guard fails, the action is excluded; (d) on a `custom` action whose `CustomActionHandler` returns false, the action is excluded; (e) ordering is deterministic and stable across repeated calls (SC-002)
- [x] T034 [P] [US3] Integration test at `tests/Integration/PerformActionTest.php`: (a) perform re-validates eligibility and availability server-side (call the engine twice in close succession with the same state, get the same outcome); (b) perform runs the action's handler if set, inside a try/catch; (c) perform closes the leaving step instance with the appropriate terminal status and opens the entering one with `entered_at` + computed `due_at`; (d) perform appends `step_completed`, `action_performed`, `step_entered` history entries; (e) `requires_comment = true` rejects when `comment` is missing/empty (throws `CommentRequiredException` and changes NO state); (f) action not in the available set throws `ActionNotAvailableException` and changes NO state; (g) ineligible user throws `NotEligibleException` and changes NO state
- [x] T035 [P] [US3] Integration test at `tests/Integration/ApprovalQuorumTest.php`: (a) on a `match_mode = any` approval step with two pending assignments, the first `acted` assignment completes the step and the other pending assignment is marked `expired`; (b) on a `match_mode = all` approval step with two pending assignments, the step completes only after both have been `acted`; (c) on terminal state, `perform` throws `WorkflowTerminalException`

### Implementation for User Story 3

- [x] T036 [P] [US3] Create the `AuthorizerInterface` at `src/Engines/Authorizers/AuthorizerInterface.php` (`authorize(?User, WorkflowInstance, WorkflowStepInstance): bool`) and the 5 authorizer implementations in `src/Engines/Authorizers/`: `PublicAuthorizer` (returns `true`), `RolesAuthorizer` (uses host's `user->hasRole()` or a host-resolved roles list), `PermissionsAuthorizer` (uses host's `user->can()`), `UsersAuthorizer` (checks against `WorkflowStepAssignee::assignee_value`), `CustomAuthorizerDispatcher` (resolves `WorkflowStep.custom_authorizer` FQCN via the host's class resolver and delegates)
- [x] T037 [P] [US3] Create `src/Contracts/CustomAuthorizer.php` (the host contract — `authorize(?User, WorkflowInstance, WorkflowStepInstance): bool` with the contract rules in `contracts/host-contracts.md` §1: no mutation, safe to repeat, no throw) and update `ArchTest` to require the contract to be an interface
- [x] T038 [P] [US3] Create the `ConditionEvaluatorInterface` at `src/Engines/Conditions/ConditionEvaluator.php` (dispatcher: routes by `ConditionKind`), plus 3 implementations: `ExpressionConditionEvaluator` (evaluates the structured `field/operator/value` JSON, supports `subject.*`, `context.*`, `user.*`, `instance.*` paths, 14 operators, recursion-depth cap of 10 and clause cap of 100 — throws `InvalidExpressionException` on violation), `CustomConditionEvaluator` (resolves `WorkflowCondition.evaluator` FQCN via the host's class resolver and delegates), `CompositeConditionEvaluator` (recursively evaluates `groups` with the specified AND/OR logic)
- [x] T039 [P] [US3] Create the 5 condition-value objects under `src/Engines/Expressions/`: `Expression`, `Clause`, `ClauseGroup`, `Operator` (the 14 supported operators as an enum), `Field` (helper that resolves a `subject.amount` / `context.x` / `user.id` / `instance.uuid` path against a `$context` array); also create `src/Contracts/CustomConditionEvaluator.php` (the host contract per `contracts/host-contracts.md` §2: pure, no mutation, no throw)
- [x] T040 [P] [US3] Create `src/Engines/HandlerInvoker.php` — single class that resolves a FQCN via the host's class resolver and invokes the matching `CustomActionHandler::handle()` or `CustomStepHandler::handle()` method, wrapping the call in a try/catch that returns a `HandlerInvocationResult` (success | failure with `$throwable`); used by `perform()` and `AutomationRunner`
- [x] T041 [P] [US3] Create `src/Engines/AssignmentMaterializer.php` — when entering a `task`/`approval` step, resolve assignees via the `WorkflowStepAssignee` rows (using `CustomResolver` for `assignee_type = custom` — implements `src/Contracts/CustomResolver.php` per `contracts/host-contracts.md` §5), and create one `WorkflowAssignment` row per assignee with `status = pending`
- [x] T042 [P] [US3] Create `src/Engines/QuorumEvaluator.php` — given a `match_mode` and a `step_instance_id`, returns `true` when the quorum is satisfied: for `any` the first `acted` assignment satisfies it and the rest of the `pending` ones are marked `expired` (via the DB); for `all` every `pending` assignment must become `acted`
- [x] T043 [US3] Implement `AvailableActionsResolver` at `src/Engines/AvailableActionsResolver.php` — takes the current step instance, the user, and the instance context; returns an `ActionSet` filtered by: (1) the user's eligibility via the `AuthorizerInterface` matching the step's `authorization_mode`; (2) the action's `availability_mode` (`general` always passes; `conditional` evaluates via `ConditionEvaluator`; `custom` invokes `CustomActionHandler` and treats falsy as exclude); (3) ordered by `WorkflowStepAction.sort_order` ASC then `id` ASC for determinism
- [x] T044 [US3] Implement `TransitionResolver` at `src/Engines/TransitionResolver.php` — given the current step and the chosen action (or null for automation), selects the next step: explicit `target_step_id` on the action wins (BR-AC-04); else the highest-priority matching transition wins (priority DESC, then id ASC); for `conditional`/`automatic` the guard condition is evaluated via `ConditionEvaluator`; ties broken by `WorkflowTransition.id` for determinism
- [ ] T045 [P] [US3] Implement `ActionTargetRouter` and `SequentialFallbackRouter` at `src/Engines/ActionTargetRouter.php` and `src/Engines/SequentialFallbackRouter.php` — small helpers used by `TransitionResolver` for the action-override path and the position-based fallback path (when no transition matches and `require_explicit_transitions = false`; else `TransitionNotFoundException` is thrown)
- [ ] T046 [US3] Implement `EligibilityChecker` at `src/Engines/EligibilityChecker.php` — single `isEligible(?User, WorkflowInstance, WorkflowStepInstance): bool` method that delegates to the right `AuthorizerInterface` implementation based on the step's `authorization_mode`; reusable from both `availableActions` and `perform`
- [x] T047 [US3] Implement `perform(WorkflowInstance, string, ?User = null, ?array = null): WorkflowInstance` on the engine — orchestrator: (1) reject terminal instance; (2) re-validate eligibility server-side; (3) re-resolve `availableActions` and assert the requested `actionCode` is in the set; (4) enforce `requires_comment`; (5) close the leaving step instance (status depends on `action.type` — `complete`/`approve` → `completed`, `reject` → `rejected`, `submit` → `completed`); (6) invoke the action's handler (if any) via `HandlerInvoker`; (7) run `QuorumEvaluator` and mark other `pending` assignments as `expired` (and append a `comment_added` history event with the reason) for `match_mode = any`; (8) call `TransitionResolver` to find the entering step; (9) open the entering step instance via `AssignmentMaterializer`; (10) append `step_completed` + `action_performed` + `step_entered` history; (11) if the entering step's `type = automated`, kick off `AutomationRunner` (deferred to US5); all inside a DB transaction
- [x] T048 [P] [US3] Unit tests at `tests/Unit/Authorizers/AuthorizerTest.php` covering each of the 5 authorizer modes; at `tests/Unit/Conditions/ExpressionConditionEvaluatorTest.php` covering each of the 14 operators, the recursion-depth cap, and the clause cap; at `tests/Unit/Conditions/CompositeConditionEvaluatorTest.php` covering nested AND/OR groups

**Checkpoint**: US1 + US2 + US3 fully functional. A host can define, activate, start, list-available-actions, and perform an action — the full P1 read+write loop is complete, with proper authorization, conditional availability, comment enforcement, server-side re-validation, and append-only history.

---

## Phase 6: User Story 4 — Skip and return while preserving history (Priority: P2)

**Goal**: A participant who is allowed to skip the current step or return it to an earlier step can do so, and the full audit trail is preserved (never overwritten).

**Independent Test**: A participant can skip a step that is marked skippable, or return a step that is marked returnable, and the history shows both the leaving event and the re-entry event.

### Tests for User Story 4

- [x] T049 [P] [US4] Integration test at `tests/Integration/SkipReturnTest.php`: (a) skip requires `WorkflowStep.is_skippable = true` and a passing skip guard (else `SkipNotAllowedException`); (b) skip routes per the skip transition (explicit `target_step` else next by `position`); (c) return requires `WorkflowStep.is_returnable = true` and a passing return guard; (d) return re-enters the target step as a **new** step instance; (e) both skip and return append new history events without mutating prior history
- [x] T050 [P] [US4] History regression test at `tests/Integration/HistoryAppendOnlyTest.php`: assert that no `WorkflowHistory` row is ever updated or soft-deleted across a full skip→return→perform sequence; `WorkflowHistory::count()` after the sequence equals the number of distinct operations (started, step_entered×N, step_completed×N, action_performed×N, skipped/returned, comment_added, completed); each row's `updated_at` is `null` and `deleted_at` is `null`

### Implementation for User Story 4

- [x] T051 [US4] Implement `skip(WorkflowInstance, ?User = null, ?string $comment = null): WorkflowInstance` on the engine — assert the step is `is_skippable` (else `SkipNotAllowedException`); evaluate the step's skip guard condition (if any) via `ConditionEvaluator` (else pass); close the current step instance with `status = skipped`; route to skip target via `TransitionResolver`; open the entering step instance; append `skipped` + `step_entered` history
- [x] T052 [US4] Implement `return(WorkflowInstance, WorkflowStep|string|null = null, ?User = null, ?string $comment = null): WorkflowInstance` on the engine — assert the step is `is_returnable` (else `ReturnNotAllowedException`); evaluate the return guard; resolve target: explicit `$targetStep` → that step, else the most recently completed step in the instance; close the current step instance with `status = returned`; open a new active step instance for the target (history preserves the original completed step instance); append `returned` + `step_entered` history
- [x] T053 [P] [US4] ArchTest addition: assert that no class in `src/Engines/` (other than `HistoryRecorder`) calls `->update()` or `->delete()` on a `WorkflowHistory` instance; protects the append-only invariant at the source-code level

**Checkpoint**: US1..US4 complete. The engine handles the full P1+P2 read/write surface, and skip+return preserve the audit trail (SC-007).

---

## Phase 7: User Story 5 — Run an automation pipeline without human input (Priority: P2)

**Goal**: For `automation` workflows, the system executes automated steps in sequence, evaluates automatic and conditional transitions, and only stops at a human-gated step, an end step, or a failure.

**Independent Test**: A pure-automation workflow with no human-gated steps can run from start to end in one engine call without any participant action.

### Tests for User Story 5

- [x] T054 [P] [US5] Integration test at `tests/Integration/AutomationPipelineTest.php`: (a) an `automation` workflow with all `automated` steps until an `end` step reaches `completed` in a single `start()` call; (b) an automated step whose handler throws sets the step instance and the instance to `failed` and records an `error` history event; (c) a chain that reaches a human-gated step pauses at that step and remains queryable; (d) `retry()` re-enters the failed step as a fresh step instance and resumes the chain; (e) a chain that exceeds `config('workflow.automation.max_chain_depth')` throws `AutomationLoopGuardException`

### Implementation for User Story 5

- [x] T055 [P] [US5] Create `src/Contracts/CustomStepHandler.php` (the host contract per `contracts/host-contracts.md` §4: returns an array that is merged into the step instance's `data`; pure with respect to its inputs; no engine-API calls; synchronous); the `HandlerInvoker` (T040) is updated to dispatch this contract for `step.type = automated`
- [x] T056 [US5] Implement `AutomationRunner` at `src/Engines/AutomationRunner.php` — given a freshly-opened `automated` step instance, invokes the step's handler via `HandlerInvoker`, merges the returned array into `$stepInstance->data`, then asks `TransitionResolver` for the next step (allowing `automatic` and `conditional` transitions to chain); loops until: (a) the next step is human-gated (task/approval/gateway) → stop; (b) the next step is `end` → close the instance as `completed`; (c) the next step is `automated` → continue; (d) chain depth exceeds `max_chain_depth` → throw `AutomationLoopGuardException`; each transition appends `step_completed` + `step_entered` history
- [x] T057 [US5] Implement `retry(WorkflowInstance, ?User = null, ?string $comment = null): WorkflowInstance` on the engine — assert the instance is in `failed` (else `InvalidStateException`); find the most recent `failed` step instance; re-enter that step as a new step instance (status `active`, `entered_at = now()`, `due_at` computed); set the instance to `in_progress`; append `step_entered` history
- [x] T058 [US5] Wire the automation kick-off into the orchestrator: in `perform()` and `start()`, when the entering step's `type = automated`, call `AutomationRunner` synchronously after the transaction commits (so the chain does not race with the open transaction); a deep-automation chain is bounded by `max_chain_depth` and produces one `step_completed`/`step_entered` history pair per step

**Checkpoint**: US1..US5 complete. The engine now handles the full automation family: pure-automation chains reach `completed` without human input, failures are recoverable via `retry()`, and the loop guard prevents infinite chains.

---

## Phase 8: User Story 6 — Audit a workflow via the activity feed (Priority: P2)

**Goal**: An auditor (or any participant) can view the chronological activity feed of a workflow instance, derived from the append-only history log.

**Independent Test**: After any sequence of events on an instance, the activity feed lists them in order with actor, action, comment, and timestamp.

### Tests for User Story 6

- [X] T059 [P] [US6] Integration test at `tests/Integration/ActivityFeedTest.php`: (a) after a `start → perform → perform → skip → return → perform` sequence, `history($instance)` returns the events in chronological order with `actor`, `event`, `comment`, and `fromStep`/`toStep` populated; (b) a `skipped` or `returned` event shows its `fromStep`, `toStep`, and the comment; (c) `history($instance, limit: 10)` returns at most 10 rows, most recent first; (d) `history($instance, event: 'action_performed')` filters to a single event type; (e) the feed reflects a new event on the next read (no caching); (f) the feed never contains a row twice (the append-only model is the only source)

### Implementation for User Story 6

- [X] T060 [US6] Implement `ActivityFeed` at `src/Engines/ActivityFeed.php` — single public method `read(WorkflowInstance $instance, ?int $limit = null, ?string $event = null): Collection` that returns `WorkflowHistory` rows ordered by `performed_at desc` (chronological-asc when `$limit` is set), with `fromStep` and `toStep` relations eager-loaded; the result is a live Eloquent collection (not cached) so the next read sees the latest insert
- [X] T061 [US6] Implement `history(WorkflowInstance, ?int = null, ?string = null): Collection` on the engine — thin pass-through to `ActivityFeed::read()`; ordered by `performed_at desc`; the contract guarantees that the same `WorkflowHistory` row is never returned twice and never modified between calls (implied by the append-only invariant)
- [X] T062 [P] [US6] Unit test at `tests/Unit/HistoryPayloadTest.php` asserting that every `HistoryEvent` enum case has a documented payload shape (actor, from/to step, comment, metadata) and that the recorder never serializes raw `Model` objects into the `metadata` JSON (only ids and scalars)

**Checkpoint**: US1..US6 complete. The activity feed is the canonical read-side view of an instance's life; the host UI can render it as a timeline without re-querying.

---

## Phase 9: User Story 7 — Isolate data by tenant when tenancy is enabled (Priority: P3)

**Goal**: A host application that operates in a multi-tenant mode (e.g. SaaS) needs the package to scope its queries and uniqueness rules to the current tenant, supplied by the host.

**Independent Test**: With tenancy enabled and two tenants, a workflow created in tenant A is not visible or startable in tenant B; with tenancy disabled, the package behaves as a single shared store.

### Tests for User Story 7

- [ ] T063 [P] [US7] Integration test at `tests/Integration/TenancyTest.php`: (a) with `tenant.enabled = true` and a `TenantScopeProvider` returning `1`, all `Workflow` / `WorkflowInstance` / `WorkflowAssignment` queries are auto-scoped to `tenant_id = 1`; (b) the same workflow `code` may exist in two different tenants but not within the same tenant; (c) with `tenant.enabled = false`, all `tenant_id` columns are null and uniqueness is global per code; (d) when the resolver returns `null` while tenancy is enabled, the engine performs a no-scope query (host's authorization layer is responsible for safety)

### Implementation for User Story 7

- [ ] T064 [P] [US7] Create `src/Contracts/TenantScopeProvider.php` (the host contract per `contracts/host-contracts.md` §6: `currentTenantId(): int|string|null`, cheap, side-effect-free, no global mutation)
- [ ] T065 [P] [US7] Create `src/QueryBuilder/TenantScope.php` — a `GlobalScope` that, when `config('workflow.tenant.enabled') = true`, applies `where(config('workflow.tenant.column'), '=', $resolver->currentTenantId())` to all definition and instance Eloquent queries; applied to all 10 models via a `booted()` method registered in a single trait `AppliesTenantScope`
- [ ] T066 [P] [US7] Implement tenant-aware uniqueness on `Workflow.code` — when tenancy is enabled, the `Workflow::saving` event (or a `Saving` observer) checks that no other non-deleted `Workflow` row exists with the same `(tenant_id, code)`; when tenancy is disabled, the check is global
- [ ] T067 [US7] Implement `hold(WorkflowInstance, ?User = null, ?string $comment = null): WorkflowInstance`, `resume(WorkflowInstance, ?User = null, ?string $comment = null): WorkflowInstance`, and `cancel(WorkflowInstance, ?User = null, ?string $comment = null): WorkflowInstance` on the engine — `hold` transitions the instance to `on_hold` and appends an `on_hold` history event; `resume` transitions back to `in_progress` (asserts currently `on_hold`, else `InvalidStateException`); `cancel` transitions to `cancelled` (asserts not terminal), closes all remaining `active` step instances with `status = skipped` and a `cancelled with instance` comment, appends a `cancelled` history event
- [ ] T068 [P] [US7] Unit test at `tests/Unit/QueryBuilder/TenantScopeTest.php` asserting: (a) the scope is a no-op when `tenant.enabled = false`; (b) the scope is applied when `tenant.enabled = true` and the resolver returns an int; (c) the scope is a no-op when the resolver returns `null` (host's responsibility)

**Checkpoint**: US1..US7 complete. The engine supports all three workflow families, the full read/write surface, the audit feed, and optional multi-tenancy — every documented FR is covered by at least one test.

---

## Phase 10: Polish & Cross-Cutting Concerns

**Purpose**: Improvements that affect multiple user stories; production-readiness.

- [ ] T069 [P] Implement the 4 Artisan commands under `src/Commands/`: `WorkflowListCommand` (`workflow:list`, lists active workflows with code/name/version/status), `WorkflowInstanceStatusCommand` (`workflow:status {instance?}`, shows the current step + history tail of an instance), `WorkflowHistoryCommand` (`workflow:history {instance} {--limit=20}`, pretty-prints the activity feed as a table), `WorkflowDiagnoseCommand` (`workflow:diagnose {workflow?}`, walks the workflow and reports missing start/end steps, dangling transitions, actions without handlers, etc.)
- [ ] T070 [P] Add Larastan 3 + PHPStan 2 config at `phpstan.neon.dist` with level 5 rules; add a `composer script:analyse`; document the `phpstan:phpstan` step in CI
- [ ] T071 [P] Wire `.github/workflows/tests.yml` to actually use the bumped `composer.json` matrix (Laravel {10, 11, 12, 13} × PHP {8.2, 8.3, 8.4}); add a `continue-on-error: true` fallback for the Laravel 13 + PHP 8.4 row until that combination stabilises
- [ ] T072 [P] Unit tests at `tests/Unit/StateMachines/InstanceStateMachineTest.php`, `StepInstanceStateMachineTest.php`, `AssignmentStateMachineTest.php` — every valid + invalid transition listed in `data-model.md` §2.1–§2.3
- [ ] T073 [P] Integration tests at `tests/Contract/HostContractsTest.php` that exercise the 5 host contracts end-to-end (each contract is implemented by a test-only class, registered via the test container, and exercised through the engine)
- [ ] T074 [P] Determinism test at `tests/Integration/DeterminismTest.php` (SC-002) — for an instance with 50 step instances, call `availableActions($instance, $user)` 100 times and assert the result is byte-identical each time and the 95th-percentile wall time is < 100 ms on the workbench machine
- [ ] T075 [P] `tests/Integration/HistoryEventCoverageTest.php` — assert that every state-changing operation across US1..US7 produces a `WorkflowHistory` row with a non-null `event` from the `HistoryEvent` enum and a non-null `performed_at`; the matrix covers all 11 `HistoryEvent` cases (started, step_entered, step_completed, action_performed, skipped, returned, completed, cancelled, comment_added, on_hold, error)
- [ ] T076 [P] Add a comprehensive `README.md` at the repository root with: 1-paragraph summary, install (matches `quickstart.md` step 1), 1-page overview of the public API with one code example per engine method, configuration keys, "extending with custom contracts" section, and a link to `docs/` for the full reference (placeholder — created only if the host marks the project as having user-facing docs in a future task; do not write speculative content)
- [ ] T077 [P] Add a `CHANGELOG.md` at the repository root with an `## [Unreleased]` section listing the 7 user stories as the initial feature set; semver note explaining the contract-stability rules from `contracts/workflow-engine.md` §6
- [ ] T078 Run the quickstart smoke test end-to-end: `composer install`, then in a fresh Testbench workbench app, run the 12-step `quickstart.md` script and assert each step's expected outcome (instance created, history written, completed status, etc.); CI gate on this run
- [ ] T079 Final code review pass: remove any leftover `dd`/`dump`/`var_dump`/`ray` (ArchTest already enforces it but a manual sweep catches comments), ensure every `final` class is genuinely final, run `composer script:analyse` and resolve any Larastan errors at level 5, run the full Pest suite across the matrix

**Checkpoint**: Production-ready package. All 7 user stories are complete, all architectural rules are enforced, the matrix CI passes, and the quickstart walkthrough is verified end-to-end.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately.
- **Foundational (Phase 2)**: Depends on Setup completion — BLOCKS all user stories.
- **User Stories (Phases 3–9)**: All depend on Foundational phase completion.
  - Stories can then proceed sequentially in priority order (P1 → P2 → P3) for a single developer.
  - Or in parallel (with a team) for the most aggressive timeline; US1, US2, US3 touch the same engine class so they are best done sequentially; US4, US5, US6, US7 are largely orthogonal.
- **Polish (Phase 10)**: Depends on all desired user stories being complete.

### User Story Dependencies

- **US1 (P1)**: No dependencies on other stories. Defines the lifecycle that US2..US7 all build on.
- **US2 (P1)**: Depends on US1 (needs an `active` workflow to start an instance). Adds `HistoryRecorder`, which US3..US7 all consume.
- **US3 (P1)**: Depends on US1+US2 (needs an active step instance to query available actions on). The largest single phase (~16 tasks).
- **US4 (P2)**: Depends on US1+US2+US3 (builds on the `perform` orchestrator and the `HistoryRecorder`). Adds `skip`/`return` to the engine.
- **US5 (P2)**: Depends on US1+US2+US3 (adds the `AutomationRunner` that US3's `perform()` calls into when entering an automated step). Adds `retry`.
- **US6 (P2)**: Depends on US1+US2+US3+US4+US5 (the `ActivityFeed` is a pure read-side derivation of all the history produced by the earlier stories).
- **US7 (P3)**: Largely orthogonal to US1..US6 (it adds a global scope and tenant-aware uniqueness), but US3's `perform()` orchestrator must be aware of tenancy for the `WorkflowAssignment` materialization. Adds `hold`/`resume`/`cancel`.

### Within Each User Story

- Tests are written first, asserted to FAIL before the implementation, and asserted to PASS after the implementation.
- Models before services (already in Phase 2).
- Conditions and authorizers before the resolvers that compose them.
- Resolvers before the engine orchestrator.
- Orchestrator before the side-effect-free read-side (`history`).

### Parallel Opportunities

- All Setup tasks marked [P] (T003, T004, T005) can run in parallel.
- All Foundational tasks marked [P] (T007, T008, T009, T010, T011, T013, T016, T019) can run in parallel within Phase 2.
- Inside US3, the authorizers, conditions, expressions, and HandlerInvoker tasks (T036–T042) can all be done in parallel before the resolvers (T043–T046).
- Inside US7, the contract (T064) can be created in parallel with the scope (T065) and the uniqueness check (T066).
- All test tasks within a story (the [P] tasks under "Tests for User Story N") can be written and run in parallel before the implementation.
- Different user stories can be worked on in parallel by different team members (with the dependency notes above respected).

---

## Parallel Example: User Story 3 (the largest phase)

```bash
# Phase 5 prep — all five value-object + condition + authorizer bundles run together:
Task: "Create the AuthorizerInterface and 5 authorizer implementations in src/Engines/Authorizers/"
Task: "Create the ConditionEvaluator dispatcher and 3 implementations in src/Engines/Conditions/"
Task: "Create the 5 expression value objects and CustomConditionEvaluator contract in src/Engines/Expressions/ + src/Contracts/"
Task: "Create the HandlerInvoker in src/Engines/HandlerInvoker.php"
Task: "Create the AssignmentMaterializer and QuorumEvaluator in src/Engines/"

# Then the resolvers (depend on the above) run in parallel:
Task: "AvailableActionsResolver at src/Engines/AvailableActionsResolver.php"
Task: "TransitionResolver at src/Engines/TransitionResolver.php"
Task: "ActionTargetRouter + SequentialFallbackRouter at src/Engines/"
Task: "EligibilityChecker at src/Engines/EligibilityChecker.php"

# Then the orchestrator (depends on the resolvers):
Task: "perform() in src/Engines/WorkflowEngine.php"
```

---

## Implementation Strategy

### MVP First (User Stories 1, 2, 3 — the P1 surface)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL — blocks all stories)
3. Complete Phase 3: User Story 1
4. Complete Phase 4: User Story 2
5. Complete Phase 5: User Story 3
6. **STOP and VALIDATE**: a host can install the package, define a workflow, activate it, start an instance, list available actions, and perform one — the full P1 read/write loop is complete
7. **Tag and release as `v0.1.0` (pre-stable MVP)** for early adopters

### Incremental Delivery

1. Setup + Foundational → foundation ready
2. US1 → test independently → tag `v0.1.0-mvp1` (define + activate)
3. US2 → test independently → tag `v0.1.0-mvp2` (start + read)
4. US3 → test independently → tag `v0.2.0` (full P1 read+write)
5. US4 → test independently → tag `v0.3.0` (skip + return, P2)
6. US5 → test independently → tag `v0.4.0` (automation)
7. US6 → test independently → tag `v0.5.0` (activity feed)
8. US7 → test independently → tag `v0.6.0` (tenancy)
9. Phase 10 (polish) → tag `v1.0.0` (production release)

### Parallel Team Strategy (4 engineers)

1. All together: Phase 1 + Phase 2 (≈ 19 tasks; ~2 days).
2. After Phase 2:
   - Engineer A: US1 + US2 (the definition + start path)
   - Engineer B: US3 (the engine orchestrator, the largest single chunk)
   - Engineer C: US7 (orthogonal; can land in parallel with US3)
   - Engineer D: Phase 10 prep (CI matrix, Larastan, contract tests, quickstart smoke)
3. US3, US4, US5, US6 must be done sequentially (they all touch the engine class).
4. US4, US5, US6 can run in parallel once US3 is merged (they're additive on top of the engine).
5. US7 can run in parallel throughout (it only touches models, scopes, and the engine's `hold`/`resume`/`cancel` methods).

---

## Notes

- [P] tasks = different files, no dependencies on incomplete tasks.
- [Story] label maps task to a specific user story (US1..US7) for traceability.
- Each user story is independently completable and testable.
- Tests are written FIRST in each story, asserted to FAIL, then the implementation is written, then re-run to PASS.
- The package commits after every task or logical group; the optional `before_tasks` / `after_tasks` git auto-commit hooks can be enabled per project preference.
- Stop at any checkpoint to validate the story independently before proceeding.
- Avoid: vague tasks, same-file conflicts, cross-story dependencies that break independence.
- All `composer.json` bumps land in Phase 1, T001; do not let other tasks touch `composer.json`.
- All `ArchTest` rules are added in Phase 2, T018; subsequent tasks must satisfy the rules they introduce.
- The `WorkflowEngine` class grows incrementally (US1 adds `versions`/`activate`/`createNewVersion`; US2 adds `start`/`currentStep`; US3 adds `availableActions`/`perform`; etc.) — do not rewrite it from scratch between stories.


---

## Phase 11: PHP Attributes authoring layer (NEW — added 2026-06-06 per `plan.md` §PHP Attributes authoring layer)

The attribute layer is a typed, IDE-discoverable, compile-time-validated authoring surface that **compiles to the same DB rows the engine already reads**. The runtime engine is unchanged. This phase ships 6 attribute primitives, 1 compiler, 1 loader, 1 Artisan command, compile-time invariant enforcement, and an end-to-end integration test. See `contracts/attributes.md` for the full contract.

- [ ] T080 Create `src/Attributes/AsWorkflow.php` — `final` class extending `\Attribute` with `flags = Attribute::TARGET_CLASS`; readonly constructor accepting `code: string`, `name: string`, `subject: ?string = null`, `type: ?string = null`, `description: ?string = null`, `tenantId: ?int = null`; unit test verifies target + default arguments
- [ ] T081 Create `src/Attributes/Step.php` — `final` class with `flags = Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY`; readonly constructor accepting `code: string`, `name: string`, `type: StepType|string`, `position: int = 0`, `authorization: AuthorizationMode|string = 'public'`, `matchMode: MatchMode|string = 'all'`, `customAuthorizer: ?string = null`, `handler: ?string = null`, `isSkippable: bool = false`, `isReturnable: bool = false`, `slaSeconds: ?int = null`, `config: ?array = null`; unit test verifies target
- [ ] T082 Create `src/Attributes/Action.php` — `final` class with `flags = Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE`; readonly constructor accepting `code: string`, `name: string`, `type: ActionType|string`, `label: ?string = null`, `availabilityMode: ActionAvailabilityMode|string = 'general'`, `guardCondition: string|array|null = null`, `guardClass: ?string = null`, `targetStep: ?string = null`, `requiresComment: bool = false`, `handler: ?string = null`, `sortOrder: int = 0`; unit test verifies repeatable flag
- [ ] T083 Create `src/Attributes/Condition.php` — `final` class with `flags = Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE`; readonly constructor accepting `code: string`, `name: ?string = null`, `kind: ConditionKind|string = 'expression'`, `expression: string|array|null = null`, `evaluator: ?string = null`; unit test verifies both targets + repeatable
- [ ] T084 Create `src/Attributes/Authorizer.php` — `final` class with `flags = Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY`; readonly constructor accepting `class: string` (FQCN), `params: array = []`; unit test verifies target
- [ ] T085 Create `src/Attributes/Assignee.php` — `final` class with `flags = Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE`; readonly constructor accepting `type: AssigneeType|string`, `value: string`, `customResolver: ?string = null`, `sortOrder: int = 0`; unit test verifies target + repeatable
- [ ] T086 Create `src/Attributes/Transition.php` — `final` class with `flags = Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE`; readonly constructor accepting `from: string`, `to: string`, `on: string` (action code), `when: ?string = null` (inline condition string), `priority: int = 0`, `type: TransitionType|string = 'unconditional'`; unit test verifies target + repeatable
- [ ] T087 Create `src/Attributes/Compilation/{CompiledWorkflow, CompiledStep, CompiledAction, CompileContext}.php` — 4 readonly DTOs; `CompiledWorkflow` carries `code`, `name`, `subject`, `type`, `version`, `tenantId`, `steps[]`, `transitions[]`, `conditions[]`, `assignees[]`; unit test verifies immutability + serialisation (`jsonSerialize` round-trip)
- [ ] T088 Create `src/Attributes/Compilation/AttributeCompiler.php` — `final` class implementing `AttributeCompilerContract` (interface in same namespace); uses `ReflectionClass` + `ReflectionAttribute` to walk a workflow class, validates targets, parses inline expression strings via existing `ExpressionConditionEvaluator`, builds the DTO tree; throws `InvalidWorkflowException` / `InvalidExpressionException`; unit test compiles a small fixture class and asserts the resulting `CompiledWorkflow` matches a hand-built expected DTO
- [ ] T089 Create `src/Attributes/Discovery/AttributeWorkflowLoader.php` — `final` class using `Symfony\Component\Finder\Finder` to scan `config('workflow.attribute_paths')` (default `['app/Workflows']`); collects all classes with `#[AsWorkflow]`; respects `config('workflow.compile_on_boot')`; registers a `booted()` hook on the service provider to optionally auto-compile
- [ ] T090 Create `src/Commands/CompileWorkflowAttributesCommand.php` — `php artisan workflow:compile-attributes [--path=...] [--dry-run] [--strict] [--tenant=...] [--version=...]`; opens a `DB::transaction()`, calls `AttributeCompiler::compileAll($ctx)`, persists each `CompiledWorkflow` into the 6 definition tables via `Model::upsert(...)` keyed by `(tenant_id, code, version)`, prints a tabular report, exits 0 on success / 1 on any failure; unit test mocks the compiler and asserts exit code
- [ ] T091 Create `src/Attributes/Compilation/InvariantChecker.php` — `final` class with one public method `check(CompiledWorkflow $w): array<int, array{rule: string, message: string}>` returning all invariant violations (empty if pass); implements V-1..V-11 from `contracts/attributes.md` §5; reused by both the Artisan command and the engine's `activate()` so a compile cannot produce rows that `activate()` would later reject; unit test covers all 11 rules with a fixture per rule
- [ ] T092 Add 3 new `ArchTest` rules in `tests/ArchTest.php`: (a) all `src/Attributes/*` are `final`, (b) every `src/Attributes/*.php` declares exactly one `Attribute::TARGET_*` constant in its constructor `$flags`, (c) `AttributeCompiler` only reads attribute classes declared in `HFlow\LaravelWorkflow\Attributes\\` (no reflection on other namespaces)
- [ ] T093 Integration test at `tests/Integration/CompileAttributesTest.php` — end-to-end: an `App\Workflows\OrderApprovalWorkflow` test fixture under `workbench/app/Workflows/OrderApprovalWorkflow.php` (created as part of this task, declares 1 start, 1 approval, 2 end steps + 3 actions + 3 transitions + 1 assignee via attributes) is compiled via the command, the resulting `workflows` row is activated via the engine, an instance is started on a test `Order` model, and the engine's `availableActions()` returns exactly the 2 actions declared on the approval step (`approve`, `reject`); also test idempotency (re-run command → same row count, no duplicates) and versioning (add a new `#[Action]` to the fixture and recompile → version bumps from 1 to 2, old version row remains)

