# Implementation Plan: Laravel Workflow Engine (Generic, Reusable Package)

**Branch**: `002-laravel-workflow-engine` | **Date**: 2026-06-05 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/002-laravel-workflow-engine/spec.md`

## Summary

Build a reusable Laravel package that provides a generic workflow engine for any host model. The same engine, tables, and runtime serve three workflow families — `automation`, `approval`, and `generic` — and exposes a versioned definition layer plus an append-only runtime layer (per the upstream BRD/ERD/STATE_MACHINES docs). The package targets Laravel 10 through 13, is host-agnostic, and never owns the host's `users` table or creates `lookup_*` tables or database `ENUM` types.

**The package exposes TWO complementary definition surfaces:**

1. **Database / JSON layer (always present, source of truth at runtime).** Workflows are defined as rows in `workflows`, `workflow_steps`, `workflow_step_assignees`, `workflow_step_actions`, `workflow_conditions`, and `workflow_transitions`. The engine reads these rows when an instance starts. This is what the rest of this plan describes by default.

2. **PHP Attributes layer (new, developer-experience surface).** A set of `#[Attribute]` classes — `#[AsWorkflow]`, `#[Step]`, `#[Action]`, `#[Condition]`, `#[Authorizer]`, `#[Assignee]`, `#[Transition]` — plus a compiler service that walks a host-supplied directory (default `app/Workflows/`) and produces the same database rows. The DB layer is still the runtime source of truth; the attribute layer is a typed, discoverable, IDE-friendly authoring surface that compiles to the same rows. Hosts may use one, the other, or both (mixed-mode is allowed; attribute-compiled rows merge with any manually-authored rows by `code`).

Primary requirements: 33 functional requirements (FR-001 … FR-033), 7 prioritized user stories, 12 success criteria, 10 database tables, 15 PHP enums, 6 host-supplied contracts, 1 facade, 1 service, 4 Artisan commands, **6 new PHP attribute classes + 1 compiler + 1 Artisan command** for the attribute layer. The technical approach is recorded in `research.md`; the data model in `data-model.md`; the public API in `contracts/workflow-engine.md`; the host-supplied contracts in `contracts/host-contracts.md`; the attribute contract in `contracts/attributes.md` (new); and a 5-minute walk-through in `quickstart.md`.

## Technical Context

**Language/Version**: PHP `^8.4` (covers Laravel 10 LTS through 13; PHP 8.4 is the highest minor supported by all four targeted Laravel majors).

**Primary Dependencies**:
- `spatie/laravel-package-tools ^1.16` (existing; service-provider scaffold, `hasMigration()`, `hasConfigFile()`, Facade accessor).
- `illuminate/contracts: ^10.0 || ^11.0 || ^12.0 || ^13.0` (updated from `^11.0 || ^12.0 || ^13.0`).
- `php` (built-in enums; no extra library for the condition expression language; built-in `#[Attribute]` for the new PHP-attribute authoring layer).
- Host application class resolver (Laravel's container) for the six host-supplied contracts.
- `doctrine/annotations` is **NOT** used — PHP 8.0+ native `#[Attribute]` is sufficient and gives compile-time validation.

No new runtime dependencies are added. The attribute layer uses only native PHP `#[Attribute]` and the framework's own reflection utilities (`ReflectionClass`, `ReflectionAttribute`, `ReflectionMethod`).

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
- **1 facade (`HFlow\LaravelWorkflow\Facades\LaravelWorkflow`).
- 1 service (`HFlow\LaravelWorkflow\Engines\WorkflowEngine`).
- 4 base Artisan commands (status, list, history, diagnose).
- **1 new Artisan command (`workflow:compile-attributes`) for the attribute layer.
- **6 PHP attribute classes + 1 compiler service + 1 attribute loader for the new authoring layer.
- ~50 source files in `src/` for the core engine; **+10 files for the attribute layer (~60 total)**.

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
│   ├── host-contracts.md
│   └── attributes.md     # NEW: PHP attribute contract (targets, arguments, validation)
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
├── Attributes/                                  # NEW: PHP attribute authoring layer
│   ├── AsWorkflow.php                           # #[AsWorkflow] on a workflow-class
│   ├── Step.php                                 # #[Step] repeatable, on methods/properties
│   ├── Action.php                               # #[Action] repeatable, on methods
│   ├── Condition.php                            # #[Condition] for guards & transitions
│   ├── Authorizer.php                           # #[Authorizer] for custom authorizer FQCN
│   ├── Assignee.php                             # #[Assignee] for step assignees
│   ├── Transition.php                           # #[Transition] for from->to edges
│   ├── Compilation/
│   │   ├── AttributeCompiler.php                # Reflection-based compiler
│   │   ├── CompiledWorkflow.php                 # DTO returned by the compiler
│   │   ├── CompiledStep.php
│   │   ├── CompiledAction.php
│   │   └── CompileContext.php                   # Carries config + tenant during compile
│   └── Discovery/
│       ├── AttributeWorkflowLoader.php          # Scans app/Workflows/ + registers handlers
│       └── AutoloadedAttributeRegistry.php      # In-memory cache of discovered workflows
├── Commands/                                    # Artisan
│   ├── WorkflowListCommand.php
│   ├── WorkflowInstanceStatusCommand.php
│   ├── WorkflowHistoryCommand.php
│   ├── WorkflowDiagnoseCommand.php
│   └── CompileWorkflowAttributesCommand.php      # NEW: `php artisan workflow:compile-attributes`
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

## PHP Attributes authoring layer (NEW)

A typed, IDE-friendly, compile-time-validated alternative to authoring workflow definitions in JSON or by writing rows directly into the `workflows` table. Hosts place dedicated workflow classes in `app/Workflows/` (configurable via `config('workflow.attribute_paths')`). Each class is decorated with `#[AsWorkflow]` and its members carry `#[Step]`, `#[Action]`, `#[Condition]`, `#[Authorizer]`, `#[Assignee]`, and `#[Transition]` attributes. A compiler (`AttributeCompiler`) reads the attributes via reflection and produces `CompiledWorkflow`/`CompiledStep`/`CompiledAction` DTOs. The `CompileWorkflowAttributesCommand` (`php artisan workflow:compile-attributes`) writes those DTOs into the existing `workflows` / `workflow_steps` / `workflow_step_actions` / `workflow_conditions` / `workflow_step_assignees` / `workflow_transitions` tables inside a single transaction. The engine runtime is **unchanged** — it still reads from the DB. The attribute layer is strictly an authoring convenience.

### Design rules

1. **DB is the runtime source of truth.** The engine never reads attribute classes at runtime. Attribute classes are compiled to rows once (CI, deploy step, or on demand) and the engine operates on rows.
2. **Mixed mode is allowed.** A host may author some workflows via attributes and others directly as DB rows. The compiler merges by `(tenant_id, code)` — a re-compile is idempotent and updates existing rows, never duplicates.
3. **Compile-time validation.** The compiler re-runs the engine's `activate()` invariants at compile time (≥1 end step, exactly 1 start step, no orphan transitions, every `#[Transition]`'s `to` resolves to a known step) and refuses to write anything that would not pass `activate()`.
4. **Strict types + `Attribute::TARGET_*`.** Each `#[Attribute]` declares the precise `Attribute::TARGET_*` (e.g., `#[AsWorkflow]` is `TARGET_CLASS`; `#[Step]` is `TARGET_METHOD | TARGET_PROPERTY`; `#[Action]` is `TARGET_METHOD`). `Attribute::IS_REPEATABLE` is set on every attribute that may appear more than once per target.
5. **No magic.** Attribute arguments are restricted to scalar / enum / array-of-scalar values. A `#[Condition]` never embeds a closure — expressions stay in the existing JSON expression language.
6. **One compile = one transaction.** If any row in the workflow fails to compile, the whole transaction rolls back and the command exits non-zero.
7. **Versioned on first compile, then immutable.** The first successful compile of a given `(code)` writes `version = 1`. Subsequent compiles create `version + 1` and call `createNewVersion()` (US1) so the engine's versioning rules apply unchanged.

### Example authoring shape (target API)

```php
namespace App\Workflows;

use HFlow\LaravelWorkflow\Attributes\AsWorkflow;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Action;
use HFlow\LaravelWorkflow\Attributes\Transition;
use HFlow\LaravelWorkflow\Attributes\Assignee;
use HFlow\LaravelWorkflow\Attributes\Authorizer;
use HFlow\LaravelWorkflow\Attributes\Condition;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;

#[AsWorkflow(code: 'order_approval', name: 'Order Approval', subject: Order::class, type: 'approval')]
final class OrderApprovalWorkflow
{
    #[Step(code: 'start', type: StepType::Start, position: 1)]
    public function start(): void {}

    #[Step(code: 'manager_review', type: StepType::Approval, authorization: AuthorizationMode::Roles, position: 2)]
    #[Assignee(type: 'role', value: 'manager')]
    #[Action(code: 'approve', type: ActionType::Approve, label: 'Approve')]
    #[Action(code: 'reject',  type: ActionType::Reject,  label: 'Reject', requiresComment: true)]
    #[Transition(from: 'start',         to: 'manager_review', on: 'approve')]
    #[Transition(from: 'manager_review', to: 'end',           on: 'approve')]
    #[Transition(from: 'manager_review', to: 'rejected',      on: 'reject',  when: 'subject.amount > 10000')]
    public function managerReview(): void {}

    #[Step(code: 'end', type: StepType::End, position: 99)]
    public function end(): void {}

    #[Step(code: 'rejected', type: StepType::End, position: 100)]
    public function rejected(): void {}
}
```

### Layer integration with existing components

- `#[Step(authorization: Roles)]` produces the same `workflow_steps.authorization_mode = 'roles'` row the engine already understands; no new engine code is needed.
- `#[Condition]` on a transition compiles to a `workflow_conditions` row with `kind = expression` and the inline `when: 'subject.amount > 10000'` string is parsed by the existing `ExpressionConditionEvaluator` (BR-C-02).
- `#[Authorizer]` writes a `workflow_steps.custom_authorizer` FQCN; the existing `CustomAuthorizerDispatcher` resolves it (BR-A-05).
- `#[Assignee]` writes a `workflow_step_assignees` row; the existing `AssignmentMaterializer` consumes it at step entry (BR-X-24).

The attribute layer therefore does not require any new engine code paths. The only new code is the attributes, the compiler, the loader, and the Artisan command.

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
| PHP Attributes authoring layer (NEW) | Hosts may want a typed, IDE-discoverable, compile-time-validated surface in addition to the DB layer; the compiler emits the same rows the engine already reads so no new runtime engine code is added. The cost is 6 attribute classes + 1 compiler + 1 loader + 1 command + tests; the benefit is faster onboarding and a self-documenting workflow DSL. (FR-001, FR-002 — author-time only) |

## Generated artifacts (Phase 0 + Phase 1)

| File | Status | Purpose |
|---|---|---|
| `research.md` | DONE | Resolves all open technical questions (PHP version, dependencies, storage, testing, target platform, project type, performance, constraints, scale/scope, public API surface, migration strategy, risk register, out-of-scope confirmations). |
| `data-model.md` | DONE | The 10 database tables, the 15 PHP enums, the 4 state machines, the relationship summary, the validation rules, the append-only history invariants. |
| `contracts/workflow-engine.md` | DONE | The full public `WorkflowEngine` service contract: 14 methods, error model, determinism guarantees, observability, versioning policy. |
| `contracts/host-contracts.md` | DONE | The 6 host-supplied contracts (`CustomAuthorizer`, `CustomConditionEvaluator`, `CustomActionHandler`, `CustomStepHandler`, `CustomResolver`, `TenantScopeProvider`) with examples and registration patterns. |
| `contracts/attributes.md` | NEW (to be added) | The 6 PHP attribute classes — `#[AsWorkflow]`, `#[Step]`, `#[Action]`, `#[Condition]`, `#[Authorizer]`, `#[Assignee]`, `#[Transition]` — with their `Attribute::TARGET_*` / `IS_REPEATABLE` flags, accepted arguments, and the compile-time validation rules the `AttributeCompiler` enforces before writing rows. |
| `quickstart.md` | DONE | 12-step installation and usage walk-through that a host developer can follow to install the package, define a workflow, start an instance, list available actions, perform one, and read the activity feed. |
| `AGENTS.md` (project root) | UPDATED | Now points to this plan file between the `<!-- SPECKIT START -->` and `<!-- SPECKIT END -->` markers. |

## Next phase

`/speckit.tasks` — generates a dependency-ordered, implementation-ready task list from this plan. **The task list must be extended** with a new Phase 2.5 / Phase 11 covering the attribute layer:

- **T080–T086 (7 tasks): attribute primitives.** `#[AsWorkflow]`, `#[Step]`, `#[Action]`, `#[Condition]`, `#[Authorizer]`, `#[Assignee]`, `#[Transition]` (each as a `final` class under `src/Attributes/` with a typed readonly DTO constructor, declared `Attribute::TARGET_*` and `IS_REPEATABLE`, unit-tested with reflection).
- **T087 (1 task): compiled DTOs.** `CompiledWorkflow` / `CompiledStep` / `CompiledAction` / `CompileContext` readonly DTOs returned by the compiler.
- **T088 (1 task): `AttributeCompiler`.** Reflection-based compiler that walks a class, validates targets, resolves `#[Condition(when: '...')]` strings into `Expression` DTOs, and produces the DTO tree.
- **T089 (1 task): `AttributeWorkflowLoader`.** Discovers attribute classes from `config('workflow.attribute_paths')` (default `app/Workflows/`) and triggers compile on package boot (when `config('workflow.compile_on_boot') = true`) or on command.
- **T090 (1 task): `CompileWorkflowAttributesCommand`.** `php artisan workflow:compile-attributes [--dry-run] [--path=...]` that opens a transaction, persists the compiled DTOs into the existing 6 definition tables, and exits non-zero on any validation error.
- **T091 (1 task): compile-time invariant enforcement.** Reuse `InstanceStateMachine` / `WorkflowStateMachine` to assert: ≥1 end step, exactly 1 start step, no orphan transitions, every `to` resolves, `requires_comment` is set for every `Reject` action, `match_mode` is in the allowed set.
- **T092 (1 task): ArchTest additions.** All `src/Attributes/*` classes are `final`; each declares `Attribute::TARGET_*`; the compiler only reads attributes declared in `HFlow\LaravelWorkflow\Attributes\\`.
- **T093 (1 task): integration test.** End-to-end: an `App\Workflows\OrderApprovalWorkflow` test fixture under `workbench/app/Workflows/` is compiled via the command, the resulting `workflows` row is activated via the engine, an instance is started, and the engine's `availableActions()` returns the actions declared in the attributes.

The plan is complete; the command can be invoked next. After Phase 2 (foundation) and Phases 3-9 (user stories) ship, Phase 10 (polish) should also include `workflow:compile-attributes` in CI via the existing `.github/workflows/lint.yml` (or a new `compile.yml`) so PRs that change attribute classes fail compile-fast.


