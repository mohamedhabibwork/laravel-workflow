# Implementation Plan: Laravel Workflow Engine (Generic, Reusable Package)

**Branch**: `002-laravel-workflow-engine` | **Date**: 2026-06-05 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/002-laravel-workflow-engine/spec.md`

## Summary

Build a reusable Laravel package that provides a generic workflow engine for any host model. The same engine, tables, and runtime serve three workflow families — `automation`, `approval`, and `generic` — and exposes a versioned definition layer plus an append-only runtime layer (per the upstream BRD/ERD/STATE_MACHINES docs). The package targets Laravel 10 through 13, is host-agnostic, and never owns the host's `users` table or creates `lookup_*` tables or database `ENUM` types.

Primary requirements: 33 functional requirements (FR-001 … FR-033), 7 prioritized user stories, 12 success criteria, 10 database tables, 15 PHP enums, 6 host-supplied contracts, 1 facade, 1 service, 4 Artisan commands. The technical approach is recorded in `research.md`; the data model in `data-model.md`; the public API in `contracts/workflow-engine.md`; the host-supplied contracts in `contracts/host-contracts.md`; and a 5-minute walk-through in `quickstart.md`.

## Technical Context

**Language/Version**: PHP `^8.4` (covers Laravel 10 LTS through 13; PHP 8.4 is the highest minor supported by all four targeted Laravel majors).

**Primary Dependencies**:
- `spatie/laravel-package-tools ^1.16` (existing; service-provider scaffold, `hasMigration()`, `hasConfigFile()`, Facade accessor).
- `illuminate/contracts: ^10.0 || ^11.0 || ^12.0 || ^13.0` (updated from `^11.0 || ^12.0 || ^13.0`).
- `php` (built-in enums; no extra library for the condition expression language).
- Host application class resolver (Laravel's container) for the six host-supplied contracts.

No new runtime dependencies are added.

**Storage**: a single relational database through Eloquent (the host's connection). All ten tables are created by one combined migration published via `Package::hasMigration('create_workflow_table')`. All `type` / `status` columns are `VARCHAR` and constrained by backed PHP enums. The append-only `workflow_histories` table has `created_at` only (no `updated_at`, no soft delete). Tenancy is optional via a host-supplied `TenantScopeProvider`.

**Testing**:
- Pest 4 + `pest-plugin-arch ^4.0` (existing).
- Orchestra Testbench `^11.0 || ^10.0 || ^9.0 || ^8.0` (existing; bumped to include v8 for Laravel 10 support).
- Larastan 3 + PHPStan 2 (existing).
- Layers: unit (state machines, condition evaluators, each authorizer), integration (full start → action → complete in Testbench + SQLite), arch (no `dd`/`dump`/`ray`, no `DB::raw` in the engine, enums are `final`, models are `final`, history is write-once), contract (snapshot of the public `WorkflowEngine` API surface).

**Target Platform**: any platform on which Laravel 10–13 runs (Linux, macOS, Windows). No platform-specific code.

**Project Type**: Composer library / Laravel package. Host-agnostic, reusable, installable into any Laravel 10–13 application. Tested in isolation via Testbench.

**Performance Goals**: `availableActions($instance, $user)` p95 < 100 ms for instances with up to 50 step instances (SC-002). One history INSERT per state-changing operation. Eager-load step + assignees + actions + transitions on the active step instance. The expression-condition evaluator is pure PHP and runs in a single pass over a small JSON tree.

**Constraints**:
- No `lookup_*` tables, no DB `ENUM` types (FR-033, BR §7).
- No ownership of the host's `users` table (FR-032).
- Configurable table prefix, default `workflow_` (FR-031).
- Append-only history; no updates, no deletes (FR-027, BR-H-03).
- Server-side re-validation on every action perform (FR-021, BR-X-11).
- First-valid-action-wins for `match_mode = any`; database transactions for consistency, but no row-level locking abstraction (SC-011).
- Synchronous handlers; async is the host's job (Assumption in `spec.md`).
- Pinned workflow version — instances never follow a new version (FR-003, BR-D-04).

**Scale/Scope**:
- 1 Composer package.
- 10 database tables (6 definition + 4 runtime).
- 15 backed PHP enums.
- 6 host-supplied contracts in `src/Contracts/`.
- 1 facade (`HFlow\LaravelWorkflow\Facades\LaravelWorkflow`).
- 1 service (`HFlow\LaravelWorkflow\Engines\WorkflowEngine`).
- 4 Artisan commands (status, list, history, diagnose).
- ~50 source files in `src/`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

The constitution file at `.specify/memory/constitution.md` is a placeholder template (all `[PRINCIPLE_N_NAME]` and `[SECTION_N_NAME]` slots are unfilled). No gates are defined, so the check is "**no gates defined**" — there is nothing to violate. The plan proceeds.

**Post-design re-check**: still no gates defined. The plan stays aligned with the spec's documented constraints and assumptions (which serve as the project's de-facto guiding principles until a real constitution is ratified).

## Project Structure

### Documentation (this feature)

```text
specs/002-laravel-workflow-engine/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (/speckit.plan command) — DONE
├── data-model.md        # Phase 1 output (/speckit.plan command) — DONE
├── quickstart.md        # Phase 1 output (/speckit.plan command) — DONE
├── contracts/           # Phase 1 output (/speckit.plan command) — DONE
│   ├── workflow-engine.md
│   └── host-contracts.md
├── checklists/
│   └── requirements.md  # Spec quality checklist (from /speckit.specify) — PASSING
├── spec.md              # Feature spec (from /speckit.specify)
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)

The package is **a single Composer project** (Laravel package). The existing Spatie `laravel-package-tools` skeleton is reused; new directories are added under `src/`.

```text
src/
├── LaravelWorkflow.php                          # Service-container entry / facade target
├── LaravelWorkflowServiceProvider.php           # Package service provider (Spatie scaffold)
├── Facades/
│   └── LaravelWorkflow.php                      # Static facade
├── Actions/                                     # Value objects for the engine API
│   ├── ActionSet.php                            # Returned by availableActions()
│   └── Action.php                               # Single available-action
├── Commands/                                    # Artisan
│   ├── WorkflowListCommand.php
│   ├── WorkflowInstanceStatusCommand.php
│   ├── WorkflowHistoryCommand.php
│   └── WorkflowDiagnoseCommand.php
├── Concerns/                                    # Eloquent traits
│   ├── HasUuid.php
│   ├── HasAuditColumns.php
│   ├── BelongsToWorkflow.php
│   └── AppendOnlyHistory.php                    # Locks down the history model
├── Contracts/                                   # Public interfaces (host implements)
│   ├── WorkflowEngine.php                       # The single engine service contract
│   ├── CustomAuthorizer.php
│   ├── CustomConditionEvaluator.php
│   ├── CustomActionHandler.php
│   ├── CustomStepHandler.php
│   ├── CustomResolver.php
│   └── TenantScopeProvider.php
├── Engines/                                     # Core engine (the runtime)
│   ├── WorkflowEngine.php                       # Implementation of Contracts\WorkflowEngine
│   ├── AvailableActionsResolver.php             # BR-X-06..10
│   ├── EligibilityChecker.php                   # BR-A-01..06
│   ├── TransitionResolver.php                   # BR-R-01..05
│   ├── ActionTargetRouter.php                   # BR-AC-04, BR-X-12
│   ├── SequentialFallbackRouter.php             # BR-R-05
│   ├── AutomationRunner.php                     # BR-X-21..23
│   ├── AssignmentMaterializer.php               # BR-X-24
│   ├── QuorumEvaluator.php                      # BR-X-25
│   ├── VersionPinner.php                        # BR-D-04, BR-X-02
│   ├── HistoryRecorder.php                      # BR-H-01..03 (append-only INSERTs)
│   ├── ActivityFeed.php                         # BR-H-04 (derived reader)
│   ├── HandlerInvoker.php                       # Wraps custom handler calls + try/catch
│   ├── Authorizers/                             # BR-A-01..06
│   │   ├── AuthorizerInterface.php
│   │   ├── PublicAuthorizer.php
│   │   ├── RolesAuthorizer.php
│   │   ├── PermissionsAuthorizer.php
│   │   ├── UsersAuthorizer.php
│   │   └── CustomAuthorizerDispatcher.php
│   ├── Conditions/                              # BR-C-01..04
│   │   ├── ConditionEvaluator.php               # Dispatcher
│   │   ├── ExpressionConditionEvaluator.php
│   │   ├── CustomConditionEvaluator.php
│   │   └── CompositeConditionEvaluator.php
│   └── Expressions/                             # Expression-condition value objects
│       ├── Expression.php
│       ├── Clause.php
│       ├── ClauseGroup.php
│       └── Operator.php
├── Enums/                                       # Backed enums (one per status/type field)
│   ├── WorkflowType.php
│   ├── WorkflowStatus.php
│   ├── StepType.php
│   ├── AuthorizationMode.php
│   ├── MatchMode.php
│   ├── AssigneeType.php
│   ├── ActionType.php
│   ├── ActionAvailabilityMode.php
│   ├── ConditionKind.php
│   ├── TransitionType.php
│   ├── InstanceStatus.php
│   ├── StepInstanceStatus.php
│   ├── AssignmentStatus.php
│   ├── HistoryEvent.php
│   └── ActorType.php
├── Exceptions/                                  # All custom exceptions
│   ├── WorkflowException.php                    # Base
│   ├── InvalidWorkflowException.php
│   ├── InvalidStateException.php
│   ├── NotEligibleException.php
│   ├── ActionNotAvailableException.php
│   ├── CommentRequiredException.php
│   ├── SkipNotAllowedException.php
│   ├── ReturnNotAllowedException.php
│   ├── TransitionNotFoundException.php
│   ├── WorkflowTerminalException.php
│   ├── WorkflowSubjectMismatchException.php
│   ├── InvalidExpressionException.php
│   └── AutomationLoopGuardException.php
├── Models/                                      # Eloquent models (10 tables)
│   ├── Workflow.php
│   ├── WorkflowStep.php
│   ├── WorkflowStepAssignee.php
│   ├── WorkflowStepAction.php
│   ├── WorkflowCondition.php
│   ├── WorkflowTransition.php
│   ├── WorkflowInstance.php
│   ├── WorkflowStepInstance.php
│   ├── WorkflowAssignment.php
│   └── WorkflowHistory.php
├── Observability/                               # Events
│   └── Events/
│       └── WorkflowHistoryRecorded.php          # Dispatched after every history INSERT
├── QueryBuilder/                                # Eloquent scopes / helpers
├── StateMachine/                                # State-transition tables
│   ├── WorkflowStateMachine.php
│   ├── InstanceStateMachine.php
│   ├── StepInstanceStateMachine.php
│   └── AssignmentStateMachine.php
└── Tags/                                        # Reserved for future model-tagging helpers

database/
├── migrations/
│   └── 2024_01_01_000000_create_workflow_table.php   # Combined, 10 tables
└── factories/
    ├── WorkflowFactory.php
    ├── WorkflowStepFactory.php
    ├── WorkflowStepAssigneeFactory.php
    ├── WorkflowStepActionFactory.php
    ├── WorkflowConditionFactory.php
    ├── WorkflowTransitionFactory.php
    ├── WorkflowInstanceFactory.php
    ├── WorkflowStepInstanceFactory.php
    ├── WorkflowAssignmentFactory.php
    └── WorkflowHistoryFactory.php

config/
└── workflow.php                                 # table_prefix, tenant, automation, history

tests/
├── Pest.php
├── TestCase.php
├── ArchTest.php
├── Unit/
│   ├── StateMachines/                           # 4 state-machine transition tables
│   ├── Conditions/                              # 3 condition evaluators
│   ├── Authorizers/                             # 5 authorizer modes
│   └── Expressions/                             # Expression condition parsing
├── Integration/
│   ├── StartInstanceTest.php
│   ├── AvailableActionsTest.php
│   ├── PerformActionTest.php
│   ├── ApprovalQuorumTest.php                   # match_mode = any / all
│   ├── SkipReturnTest.php
│   ├── AutomationPipelineTest.php
│   ├── HistoryAppendOnlyTest.php
│   ├── VersioningTest.php
│   ├── TenancyTest.php
│   └── DeterminismTest.php                      # SC-002 determinism
├── Contract/
│   └── WorkflowEngineContractTest.php           # Public API snapshot
└── Fixtures/                                    # Shared test data builders
```

**Structure Decision**: A **single Composer project** (a Laravel package) with the source organised by concern under `src/`. The Spatie `laravel-package-tools` scaffold is retained; the new directories (`Engines/`, `Enums/`, `Contracts/`, etc.) sit alongside the existing empty placeholder directories (`ActivityRecorder/`, `ApproverResolver/`, `DataValidator/`, `Exceptions/`, `QueryBuilder/`, `StateMachine/`, `States/`, `Tags/`), which are repopulated as part of the implementation tasks. Tests follow the same concern-based layout under `tests/Unit/`, `tests/Integration/`, and `tests/Contract/`. There is no separate `app/` for the host — the host application is whichever Laravel project the package is installed into; the package is tested in isolation via Testbench's workbench.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No Constitution Check violations: the constitution is empty, so there is nothing to violate. The plan's complexity is fully justified by the spec's documented scope (33 functional requirements, 10 tables, 3 workflow families, append-only history, version pinning, optional multi-tenancy). All complexity is required to satisfy explicit FR-XXX requirements that trace back to BRD/ERD/STATE_MACHINES rules.

| Concern | Justification |
|---|---|
| 10 tables instead of 1 | Required by BRD/ERD to separate definition-time from runtime data and to keep history append-only. (FR-027 … FR-033) |
| 15 enums instead of string columns | Required by BRD §7 (no DB `ENUM`, all type/status fields are `VARCHAR` constrained by PHP enums). (FR-033) |
| Append-only history table without `updated_at` | Required by BR-H-03 (history is the system of record for audit). (FR-027) |
| Workflow versioning with version pinning on instance start | Required by BR-D-04 / BR-D-06 (live instances must keep running on the version they started with). (FR-003, FR-004) |
| 5 authorization modes (public, roles, permissions, users, custom) | Required by BR-A-01..06; needed so the engine can plug into the host's auth layer. (FR-008) |
| Optional multi-tenancy | Required by BR-T-01..02 (SaaS hosts need per-tenant isolation). (FR-029, FR-030) |
| 6 host-supplied contracts (CustomAuthorizer, etc.) | Required by BR-A-05, BR-AC-05, BR-C-01, BR-X-21, BR-X-24; the engine must delegate host-specific logic to host-supplied classes. (FR-008, FR-014, FR-015, FR-023) |
| No `lookup_*` tables, no DB `ENUM` | Project-wide design constraint (BRD §7). (FR-033) |

## Generated artifacts (Phase 0 + Phase 1)

| File | Status | Purpose |
|---|---|---|
| `research.md` | DONE | Resolves all open technical questions (PHP version, dependencies, storage, testing, target platform, project type, performance, constraints, scale/scope, public API surface, migration strategy, risk register, out-of-scope confirmations). |
| `data-model.md` | DONE | The 10 database tables, the 15 PHP enums, the 4 state machines, the relationship summary, the validation rules, the append-only history invariants. |
| `contracts/workflow-engine.md` | DONE | The full public `WorkflowEngine` service contract: 14 methods, error model, determinism guarantees, observability, versioning policy. |
| `contracts/host-contracts.md` | DONE | The 6 host-supplied contracts (`CustomAuthorizer`, `CustomConditionEvaluator`, `CustomActionHandler`, `CustomStepHandler`, `CustomResolver`, `TenantScopeProvider`) with examples and registration patterns. |
| `quickstart.md` | DONE | 12-step installation and usage walk-through that a host developer can follow to install the package, define a workflow, start an instance, list available actions, perform one, and read the activity feed. |
| `AGENTS.md` (project root) | UPDATED | Now points to this plan file between the `<!-- SPECKIT START -->` and `<!-- SPECKIT END -->` markers. |

## Next phase

`/speckit.tasks` — generates a dependency-ordered, implementation-ready task list from this plan. The plan is complete; the command can be invoked next.


