# Research: Laravel Workflow Engine

**Feature**: 002-laravel-workflow-engine
**Date**: 2026-06-05
**Status**: Complete — all open questions resolved

This document records the technical decisions made for the implementation of the Laravel Workflow Engine package. It is **decision-only**: the spec at `spec.md` is the source of truth for *what* the engine must do; this file explains *how* we will build it.

---

## 1. Language & version

**Decision**: PHP `^8.4`; `illuminate/contracts: ^10.0 || ^11.0 || ^12.0 || ^13.0`.

**Rationale**:
- PHP 8.4 is already pinned in `composer.json` and is the highest minor supported by the current host framework majors. PHP 8.4 itself is supported by every Laravel major in our target range:
  - Laravel 10 (LTS) supports PHP 8.1 – 8.4.
  - Laravel 11 supports PHP 8.2 – 8.4.
  - Laravel 12 supports PHP 8.2 – 8.4.
  - Laravel 13 (when released) is expected to support PHP 8.3+.
- Restricting to PHP 8.4+ means we can use modern language features natively: enums with `match`/backed values, readonly properties, asymmetric visibility, native type intersection, `never` return type, fibers (where useful), and constructor property promotion. These are critical for the engine's "config as data" pattern (expression conditions live in JSON and must be reified into typed structures).
- Keeping PHP `^8.4` means we do NOT need to support Laravel 9 or older; the user-stated scope is Laravel 10–13.

**Alternatives considered**:
- *Lower to PHP 8.1 to broaden LTS support*: rejected — would force us to drop readonly properties, native intersection types, and asymmetric visibility, all of which we want for the engine's value classes.
- *Raise to PHP 8.5 / "current"*: rejected — not all four Laravel majors in scope support it yet.

**Update needed**: bump `composer.json` `illuminate/contracts` constraint from `^11.0||^12.0||^13.0` to `^10.0||^11.0||^12.0||^13.0`.

---

## 2. Primary dependencies

**Decision**: keep the existing `spatie/laravel-package-tools ^1.16` and the existing dev tooling. Add nothing new to runtime dependencies.

**Rationale**:
- `spatie/laravel-package-tools` already provides the package service provider scaffold, the Facade accessor, the `hasMigration()` and `hasConfigFile()` conveniences, and the `Package` builder we need.
- The engine's runtime needs are small: Eloquent, the host's container (Laravel ships this), and PHP's built-in enums. There is no need for a JSON Schema validator or a rule engine — expression conditions are evaluated by our own typed expression evaluator (a tiny recursive walker over the JSON clauses), which is fully covered by the data-model and contracts in this plan.
- `spatie/laravel-data` was considered for hydrating the JSON `expression` field on `workflow_conditions`. Rejected: it adds a runtime dependency for one use case, and the expression shape is small and stable enough to hydrate with a dedicated value object.

**Alternatives considered**:
- *Add `spatie/laravel-data`*: rejected (see above).
- *Add `league/uri` for `subject_type` parsing*: rejected — we only store the class name and use Laravel's morph map; we never URL-parse it.
- *Add a queue/CQRS library*: rejected — the engine runs synchronously by design (see Assumption: Action handler execution model in `spec.md`).

---

## 3. Storage

**Decision**: a single relational database through Eloquent; the host's Laravel connection is used. All ten tables are defined in a single migration file (`database/migrations/create_workflow_table.php.stub` is the placeholder; the real migration is generated and published by the service provider). The package's migration is registered via `Package::hasMigration(...)` so the host runs it during `php artisan migrate`.

**Rationale**:
- One combined migration keeps the schema atomic (a host installing the package either gets the full engine or none of it). Splitting into ten migrations would force us to order them correctly on every host upgrade and would slow down test setup.
- Table prefix is configurable: `config('workflow.table_prefix')` defaults to `workflow_`. The migration reads this at runtime, so a host can install the package with a different prefix without editing the migration.
- All `type`/`status` fields are `VARCHAR` constrained by PHP enum-backed columns. Laravel migrations for enums use `->string('col')` + a model cast; we never use `$table->enum(...)`. This is the BRD §7 constraint and avoids the database-coupling that real `ENUM` types bring.

**Alternatives considered**:
- *Per-table migrations (10 files)*: rejected (see above).
- *One schema file per logical group (definitions / runtime)*: rejected — single combined migration is simpler and easier to test.

---

## 4. Testing

**Decision**: Pest 4 + Pest Arch + Orchestra Testbench (multi-version) + Larastan + PHPStan. No new test framework.

**Rationale**:
- The dev tooling is already configured in `composer.json`. Pest 4 with `pest-plugin-arch` gives us the architectural assertions we need (no `dd/dump/ray`, no raw SQL in models, no public state-mutating properties on DTOs). Testbench gives us a real Laravel application inside the package so we can run migrations and boot the service container. The workbench app (`workbench/app/`) is already declared in `composer.json` for that purpose.
- Test matrix for multi-Laravel-version support is exercised via the existing `orchestra/testbench ^11.0.0||^10.0.0||^9.0.0` constraint. We add a GitHub Actions matrix (`.github/workflows/tests.yml`) to run the suite on the four Laravel majors × supported PHP versions.

**Test layers (Pest `describe` blocks)**:
- **Unit (no DB)**: state-machine transition tables, condition evaluators (expression / custom / composite), each `Authorizer` mode, history event payload shape, enum value mapping.
- **Integration (Testbench + SQLite)**: full start → action → advance → complete, approval quorum (`any` and `all`), skip / return with history preservation, automation pipeline (no human), version pinning, multi-tenancy scope, history append-only invariant.
- **Architectural (`tests/ArchTest.php`)**: no debugging functions, no DB::raw() in the engine, all models are `final`, all enums are `final`, all contracts are interfaces (not classes), the engine's public service container bindings are listed in the ArchTest.
- **Contract (snapshot-style)**: the public API of `LaravelWorkflow` (the facade target) is asserted against a known list of methods and signatures so accidental renames are caught.

**Alternatives considered**:
- *PHPUnit instead of Pest*: rejected — Pest is already the convention, and `pest-plugin-arch` gives us cheap architectural rules.
- *Cypress / Dusk for browser tests*: rejected — the engine is a backend library; UI is explicitly out of scope.

---

## 5. Target platform

**Decision**: any platform on which Laravel 10–13 runs (Linux, macOS, Windows). No platform-specific code.

**Rationale**: the package is pure PHP / Eloquent; nothing platform-specific is needed. Time handling uses the host's `Carbon` configuration; timestamps are stored as `TIMESTAMPTZ` to be timezone-safe (BRD §Conventions).

---

## 6. Project type

**Decision**: Composer library (a Laravel package). Host-agnostic, reusable, installable into any Laravel 10–13 application. Tested in isolation via Testbench.

**Rationale**: the user-stated scope is "package for laravel framework from 10 to 13 for laravel workflow engine". A Composer package is the canonical delivery vehicle.

---

## 7. Performance goals

**Decision**:
- `availableActions($instance, $user)` p95 < 100 ms for instances with up to 50 step instances. (SC-002)
- One history INSERT per state-changing operation; no batch.
- Eager-load step definition + assignees + actions + transitions on the active step instance to keep the resolver's query count small.
- The expression-condition evaluator is pure PHP (no SQL recursion); it is a single pass over a small JSON tree.

**Rationale**: the spec's success criterion SC-002 is the only explicit performance budget. We will not add caching layers beyond what Eloquent offers out of the box (no Redis, no memoization) — the engine must remain storage-agnostic at the host level (BR §7).

---

## 8. Constraints

**Decision** (encoded as enforceable rules):
- **No `lookup_*` tables, no DB `ENUM`** — all type/status fields are `VARCHAR` and constrained by PHP enums. (FR-033, BR §7)
- **No ownership of `users` table** — user FKs are nullable `BIGINT`. (FR-032, BR §7)
- **Configurable table prefix** — defaults to `workflow_`. (FR-031)
- **Append-only history** — `workflow_histories` has no `updated_at`, no `is_deleted`, no `deleted_at`. (FR-027, BR-H-03)
- **Server-side re-validation** on every action perform. (FR-021, BR-X-11)
- **First-valid-action-wins** for `match_mode = any`; the engine uses database transactions but does not abstract away row-level locking — that is the host's database. (SC-011)
- **Synchronous handlers** — automated step handlers and action handlers run synchronously; async is the host's job. (Assumption in `spec.md`)
- **Pinned workflow version** — instances never follow a new version after they are started. (FR-003, BR-D-04)

These rules are enforced by both unit tests (where applicable) and by the ArchTest (where the rule is structural).

---

## 9. Scale / scope

**Decision**:
- 1 Composer package.
- 10 database tables (6 definition + 4 runtime).
- 15 PHP enums (one per `type` / `status` / `mode` / `kind` field).
- 6 public contracts in `src/Contracts/` (CustomAuthorizer, CustomConditionEvaluator, CustomActionHandler, CustomStepHandler, CustomResolver, TenantScopeProvider).
- 1 facade (`HFlow\LaravelWorkflow\Facades\LaravelWorkflow`).
- ~50 source files in `src/` organised by concern (Engines, Models, States, Concerns, etc.).
- 4 Artisan commands (status, list, history, diagnose).

---

## 10. Public API surface (contracts beyond the spec)

The spec is the source of truth for the user-facing behavior. The following are *additional* implementation decisions that are not in the spec but are necessary to make the engine usable:

- **Entry point**: a single `WorkflowEngine` service, accessed via the `LaravelWorkflow` facade or by type-hinting `WorkflowEngine $engine` in a host's constructor. The facade is sugar; the service is the real API.
- **Configuration keys** (under `config/workflow.php`):
  - `table_prefix` (default `workflow_`)
  - `database_connection` (default `null` = host's default)
  - `tenant` (array: `enabled` (bool, default `false`), `column` (default `tenant_id`), `scope_resolver` (FQCN, optional))
  - `history` (array: `append_only` (default `true`, locked))
  - `automation` (array: `max_chain_depth` (default `50`, safety guard against infinite automation loops))
- **No middleware, no routes, no controllers, no views** are published by the package (the existing `hasViews()` in the service provider scaffold can be removed; we don't need it).
- **No HTTP-facing endpoints** — the engine is a programmatic library; hosts expose whatever HTTP surface they want.

---

## 11. Migration strategy (composer.json updates)

| File | Change |
|---|---|
| `composer.json` | Bump `illuminate/contracts` to `^10.0\|\|^11.0\|\|^12.0\|\|^13.0` |
| `composer.json` | Bump `orchestra/testbench` to `^11.0.0\|\|^10.0.0\|\|^9.0.0\|\|^8.0.0` so Laravel 10 (which needs Testbench 8) is testable. Verify by re-running `composer update --dry-run`. |
| `composer.json` | Add `allow-plugins.pestphp/pest-plugin: true` (already there) — no change. |
| `src/LaravelWorkflowServiceProvider.php` | Remove `->hasViews()` (the package ships no UI). |
| `.github/workflows/tests.yml` (new) | Matrix CI: Laravel {10, 11, 12, 13} × PHP {8.2, 8.3, 8.4} where supported. |

---

## 12. Risk register

| Risk | Mitigation |
|---|---|
| `spatie/laravel-package-tools` v1.x has breaking changes between minors on Facade accessor signatures. | Pin to `^1.16` and rely on the test suite catching any upgrade regressions. |
| `orchestra/testbench` 8/9/10/11 have different package-discovery APIs. | Use the v9+ `getPackageProviders($app)` only; do not rely on auto-discovery. The existing `TestCase` is already on this path. |
| Pest 4 + Pest Arch + Testbench across four Laravel majors is a wide matrix. | Run the matrix in CI; if a combination fails, mark it `continue-on-error` and fix forward. |
| PHP 8.4 readonly + asymmetric visibility are not always understood by older static analysers. | Larastan 3 + PHPStan 2 with strict rules will be configured in `phpstan.neon.dist`. |
| The expression-condition language is JSON and host-defined; a hostile or malformed payload could blow the stack. | Cap recursion depth (10 levels) and clause count (100 clauses); reject deeper / larger payloads at validation time. |

---

## 13. Decisions that are explicitly NOT in the plan

- No code generation (e.g. `php artisan workflow:make`) in v1. Hosts define workflows via Eloquent directly or via seeders.
- No queue integration. Handlers run synchronously.
- No event broadcasting. Hosts that need to broadcast `WorkflowAdvanced` / `WorkflowCompleted` can subscribe to the `HistoryRecorder`'s Laravel events (we dispatch a `WorkflowHistoryRecorded` event) and broadcast from there.
- No time-travel debugger, no scheduled future transitions beyond `due_at` + escalation hook.

These match the out-of-scope items already declared in `spec.md` (Assumptions).

---

## 14. Summary

All open technical questions are resolved. The package will be:

- **PHP 8.4**, **Laravel 10–13**, **Eloquent**, **Pest 4**, **Larastan 3**.
- **10 tables**, **15 enums**, **6 contracts**, **1 facade**, **1 service**, **4 commands**.
- **Synchronous**, **append-only history**, **configurable table prefix**, **optional multi-tenancy**, **pinned workflow versions**, **no `ENUM` / no `lookup_*`**.

Ready to write the data model, contracts, and quickstart.
