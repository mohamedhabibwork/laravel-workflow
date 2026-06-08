# PHP Attributes Contract — Authoring Layer

**Date**: 2026-06-06 | **Branch**: `002-laravel-workflow-engine`
**Parent**: [plan.md §PHP Attributes authoring layer (NEW)](../plan.md)
**Audience**: package maintainers and host developers who define workflows via PHP attributes.

The attribute layer is a typed, IDE-discoverable, compile-time-validated surface for authoring workflows. It compiles to the same database rows the engine already reads. **The runtime engine is unchanged** — it never sees attribute classes.

This document is the contract: the targets, the arguments, the validation rules, the merge semantics, and the failure modes. The implementation lives under `src/Attributes/` and `src/Attributes/Compilation/`.

---

## 1. Scope and non-goals

**In scope:**
- 6 attribute classes + 1 aggregate marker (`#[AsWorkflow]`) on host-authored classes.
- 1 compiler (`AttributeCompiler`) that emits `CompiledWorkflow` DTOs.
- 1 loader (`AttributeWorkflowLoader`) that discovers classes from a configured path.
- 1 Artisan command (`workflow:compile-attributes`) that writes the DTOs into the 6 definition tables.
- Compile-time re-validation of the engine's `activate()` invariants (≥1 end, exactly 1 start, no orphan transitions, etc.).

**Out of scope:**
- Runtime reading of attribute classes (the engine reads DB rows, period).
- Closures, function pointers, or any executable logic in attribute arguments.
- Authoring workflows via YAML / JSON / config files. (If needed, add later as a separate `WorkflowDefinitionParser` interface.)

---

## 2. Class index

| Attribute | Target(s) | Repeatable | File | Purpose |
|---|---|---|---|---|
| `#[AsWorkflow]` | `Attribute::TARGET_CLASS` | No | `src/Attributes/AsWorkflow.php` | Marks a class as a workflow definition; carries `code`, `name`, `subject`, `type`, `description`, `tenantId`. |
| `#[Step]` | `Attribute::TARGET_METHOD \| Attribute::TARGET_PROPERTY` | No (one per member) | `src/Attributes/Step.php` | Declares a step; carries `code`, `name`, `type`, `position`, `authorization`, `matchMode`, `customAuthorizer`, `handler`, `isSkippable`, `isReturnable`, `slaSeconds`, `config`. |
| `#[Action]` | `Attribute::TARGET_METHOD` | Yes | `src/Attributes/Action.php` | Declares a step action; carries `code`, `name`, `type`, `label`, `availabilityMode`, `guardCondition` (string or array), `guardClass`, `targetStep`, `requiresComment`, `handler`, `sortOrder`. |
| `#[Assignee]` | `Attribute::TARGET_METHOD \| Attribute::TARGET_PROPERTY` | Yes | `src/Attributes/Assignee.php` | Declares a step assignee; carries `type` (`user` / `role` / `permission` / `custom`), `value`, `customResolver`, `sortOrder`. |
| `#[Condition]` | `Attribute::TARGET_METHOD \| Attribute::TARGET_CLASS` | Yes | `src/Attributes/Condition.php` | Declares a named, reusable condition; carries `code`, `name`, `kind` (`expression` / `custom` / `group`), `expression` (string or array), `evaluator`. |
| `#[Authorizer]` | `Attribute::TARGET_METHOD \| Attribute::TARGET_PROPERTY` | No | `src/Attributes/Authorizer.php` | Declares a custom authorizer FQCN for a step; carries `class` (FQCN string), `params` (array<string,mixed>). |
| `#[Transition]` | `Attribute::TARGET_METHOD \| Attribute::TARGET_PROPERTY` | Yes | `src/Attributes/Transition.php` | Declares a `from → to` edge triggered by an action code; carries `from`, `to`, `on` (action code), `when` (inline condition expression string), `priority`, `type` (`unconditional` / `conditional` / `fallback`). |

All attributes MUST declare `Attribute::TARGET_*` and, where applicable, `Attribute::IS_REPEATABLE` in their constructor `flags` argument. The ArchTest in T092 asserts these.

---

## 3. Argument rules

1. **Scalar / enum / array-of-scalar only.** No closures, no objects that are not backed enums, no resources, no FQCNs that do not exist at compile time (we verify `class_exists` for `subject`, `customAuthorizer`, `evaluator`, `customResolver`, `guardClass`).
2. **Backed enums are accepted** for any field whose DB column is a `VARCHAR` constrained by a `WorkflowStatus` / `StepType` / `ActionType` / etc. The compiler calls `->value` to persist the string.
3. **FQCNs are stored as strings.** `subject: Order::class` is accepted at compile time and the string `'App\\Models\\Order'` is stored. `class_exists` is verified, but the string is the persisted form.
4. **Inline expression strings** in `#[Transition(when: 'subject.amount > 10000')]` are parsed by the existing `ExpressionConditionEvaluator` parser at compile time. A parse error fails the compile with `InvalidExpressionException` and the command exits non-zero.
5. **Defaults are explicit.** Every attribute argument has a typed default in the constructor so omitting it is always safe.
6. **Strict types.** Every attribute class declares `declare(strict_types=1);`.

---

## 4. Discovery and merge semantics

### 4.1 Discovery paths

`config('workflow.attribute_paths')` is a `string[]` of directories (relative to the host app base path, default `['app/Workflows']`). The `AttributeWorkflowLoader` scans each directory via `Symfony\Component\Finder\Finder` (Laravel ships with this) and collects every class that has `#[AsWorkflow]` on it. Classes without `#[AsWorkflow]` are ignored — no implicit registration.

### 4.2 Merge with existing rows

The compiler writes its output via `Model::upsert(...)` keyed by `(tenant_id, code, version, sub-entity natural key)`. A re-compile is **idempotent**:

- If `(code, version=1)` already exists, the compiler updates its row in place.
- If the host manually inserted rows for a `code` that is also attribute-managed, the compiler will overwrite them on next compile. A warning is logged.
- A new compile where the attribute set grew (e.g. a new `#[Action]` was added) bumps the workflow's `version` (via `createNewVersion()` from the engine) so live instances on the old version are untouched.

### 4.3 Compile transaction

`CompileWorkflowAttributesCommand` opens a single `DB::transaction()`. If any workflow's compile fails, the entire batch rolls back. The command exits with code 1 and prints a per-workflow report.

---

## 5. Compile-time validation

Before writing any row, the compiler re-runs these invariants (the same ones `activate()` would enforce at runtime):

| # | Rule | Failure exception | Exit code |
|---|---|---|---|
| V-1 | Exactly one step has `type = start` | `InvalidWorkflowException` | 1 |
| V-2 | At least one step has `type = end` | `InvalidWorkflowException` | 1 |
| V-3 | Every `#[Transition(from)]` resolves to a known step code | `TransitionNotFoundException` | 1 |
| V-4 | Every `#[Transition(to)]` resolves to a known step code | `TransitionNotFoundException` | 1 |
| V-5 | No two `#[Step]` in the same workflow share the same `code` | `InvalidWorkflowException` | 1 |
| V-6 | Every `#[Action(on: '...')]` in a `#[Transition]` exists on the `from` step (by `code`) | `InvalidWorkflowException` | 1 |
| V-7 | `requires_comment = true` is set on every `Reject`-type action that has no `target_step` (rejection is terminal) | `InvalidWorkflowException` | 1 |
| V-8 | `match_mode` is one of `all` / `any` / `majority` (when added) | `InvalidExpressionException` | 1 |
| V-9 | Inline expression strings parse with the existing `ExpressionConditionEvaluator` | `InvalidExpressionException` | 1 |
| V-10 | All FQCNs (`subject`, `customAuthorizer`, `evaluator`, `customResolver`, `guardClass`, `handler`) exist and implement / extend their expected type (e.g. `CustomAuthorizer`, `CustomConditionEvaluator`) | `InvalidWorkflowException` | 1 |
| V-11 | Tenant scope respected: if `tenancy.enabled` is true, every compiled row carries the current `tenantId` | `InvalidWorkflowException` | 1 |

These validations are factored into a `CompilableInvariantChecker` (in `src/Attributes/Compilation/`) and reused by `CompileWorkflowAttributesCommand` and the engine's `activate()` so a compile cannot produce a row that `activate()` would later reject.

---

## 6. Public API (compiler)

```php
namespace HFlow\LaravelWorkflow\Attributes\Compilation;

interface AttributeCompiler
{
    /**
     * Compile one attribute-decorated class into a CompiledWorkflow DTO.
     *
     * @param  class-string  $class
     * @throws \HFlow\LaravelWorkflow\Exceptions\InvalidWorkflowException
     * @throws \HFlow\LaravelWorkflow\Exceptions\InvalidExpressionException
     */
    public function compile(string $class, CompileContext $context): CompiledWorkflow;

    /**
     * Compile all classes under the configured attribute paths.
     *
     * @return array<int, CompiledWorkflow>
     */
    public function compileAll(CompileContext $context): array;
}
```

The `CompileContext` carries:
- `tenantId: ?int` (when tenancy is on)
- `strict: bool` (default true; if false, validation warnings instead of errors)
- `version: int` (1 by default; `>1` for re-compile of an existing code)
- `dryRun: bool` (default false; when true, returns DTOs without writing)

The Artisan command resolves `AttributeCompiler` from the container, calls `compileAll($context)`, persists each `CompiledWorkflow` in a transaction, prints a tabular report, and exits 0 on success / 1 on any failure.

---

## 7. Public API (Artisan)

```
php artisan workflow:compile-attributes [options]

Options:
  --path[=PATH]    Restrict compile to a single class file (relative to base path).
  --dry-run        Parse + validate + emit DTOs but do not write to DB.
  --strict         Fail on warnings (default).
  --no-strict      Warnings are reported but compile proceeds.
  --tenant[=ID]    Tenant id to scope the compile (defaults to current tenant context).
  --workflow-version[=N]    Force a specific version number (default: 1 for new, max+1 for existing).
```

Output (success):
```
  Workflows compiled: 3
   ✓ App\Workflows\OrderApprovalWorkflow       (4 steps, 6 actions, 5 transitions, 4 assignees)  → version 1
   ✓ App\Workflows\IssueEscalation            (3 steps, 3 actions, 2 transitions, 2 assignees)  → version 1
   ✓ App\Workflows\OnboardingWelcome          (2 steps, 1 action,  1 transition, 1 assignee)   → version 2

  Database:  12 rows in workflow_steps, 10 in workflow_step_actions, 8 in workflow_transitions, 7 in workflow_step_assignees, 6 in workflow_conditions, 3 in workflows.
  Time:      0.082s
```

Output (failure, non-zero exit):
```
  Workflows compiled: 0
   ✗ App\Workflows\OrderApprovalWorkflow
       V-3  Transition from "manager_review" -> "rejected" references step "rejected" which does not exist.
       V-7  Reject action "reject" on step "manager_review" must declare requires_comment = true.
   ✓ App\Workflows\IssueEscalation  (compiled but rolled back)

  Database: unchanged (transaction rolled back).
  Time:     0.041s
```

---

## 8. Error model

All compile errors extend the existing `WorkflowException` base. New compile-specific subclasses:

- `CompileValidationException` — wraps one or more invariant violations with a `getViolations(): array<int, array{rule: string, message: string}>` accessor.
- `AttributeNotFoundException` — when a host's class references a workflow attribute class that does not exist (e.g. typo'd FQCN).
- `DuplicateWorkflowCodeException` — two `#[AsWorkflow]` classes declare the same `code` under the same tenant.

These are caught by the Artisan command and printed; the transaction is rolled back; exit code is 1.

---

## 9. Testing surface

| Test | File | Asserts |
|---|---|---|
| T080–T086 attribute unit tests | `tests/Unit/Attributes/*Test.php` | Each attribute's reflection target / repeatable flag / default arguments. |
| T087 compiled DTO test | `tests/Unit/Attributes/Compilation/CompiledWorkflowTest.php` | DTO immutability + serialisation. |
| T088 compiler unit test | `tests/Unit/Attributes/Compilation/AttributeCompilerTest.php` | A small fixture class compiles to the expected `CompiledWorkflow` tree. |
| T091 invariant tests | `tests/Unit/Attributes/Compilation/InvariantCheckerTest.php` | V-1..V-11 each reject the right fixture. |
| T092 arch test | `tests/ArchTest.php` | All `src/Attributes/*` are `final`; each declares `Attribute::TARGET_*`; compiler reads only its own namespace. |
| T093 integration test | `tests/Integration/CompileAttributesTest.php` | End-to-end: fixture in `workbench/app/Workflows/OrderApprovalWorkflow.php` → `workflow:compile-attributes` → rows exist → engine `start()` succeeds → `availableActions()` returns the actions declared in the attributes. |
| Idempotency test | `tests/Integration/CompileAttributesIdempotencyTest.php` | Re-running the command twice produces the same row count; `version` stays at 1. |
| Versioning test | `tests/Integration/CompileAttributesVersioningTest.php` | Adding a new `#[Action]` and re-compiling bumps `version` to 2; the old `version = 1` row remains and live instances are untouched. |

---

## 10. Authoring-time ergonomics

- **IDE completion.** Backed enums are accepted in attribute arguments, so an IDE will surface `StepType::Start`, `StepType::End`, `StepType::Approval`, etc. No magic strings.
- **Discoverability.** A static analyser (PHPStan / Larastan) can verify that an `#[AsWorkflow]`-marked class has at least one `#[Step]` and at least one `#[Transition]`. (Optional follow-up, not in this contract.)
- **Refactor safety.** Renaming a step `code` is a single rename in PHP; the compiler re-emits the row. The runtime engine is unaffected.
- **CI integration.** The existing `.github/workflows/lint.yml` runs Pint + PHPStan; it should also run `php artisan workflow:compile-attributes --dry-run` so PRs that change attribute classes fail fast.

---

## 11. Out-of-band: when NOT to use the attribute layer

- A workflow is generated dynamically at runtime from user input (a no-code designer). Use the DB layer directly.
- A workflow has hundreds of steps with computed paths. The attribute layer is best for human-authored, statically-known workflows. The DB layer scales to dynamic workflows.
- The host has no `app/Workflows/` directory and prefers a YAML or JSON workflow file. (Future: a `WorkflowDefinitionParser` interface can be added; not in this contract.)
