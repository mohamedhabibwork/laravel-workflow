# Changelog

All notable changes to `mohamedhabibwork/laravel-workflow` will be documented in this file.

## [Unreleased]

### Added

- Versioned workflow definition lifecycle: define workflows, activate drafts, list versions, and create immutable new drafts from existing versions.
- Runtime workflow instances attached to arbitrary Eloquent host models with pinned workflow versions and current-step resolution.
- Deterministic available-action resolution with authorization modes, conditions, custom handlers, server-side re-validation, quorum handling, and required-comment enforcement.
- Skip and return operations that preserve history by opening fresh step instances instead of rewriting prior state.
- Synchronous automation pipeline with automated step handlers, retry support, failure recording, and a bounded chain-depth guard.
- Append-only activity feed backed by `workflow_histories`, with typed history events and actor/comment/from-step/to-step payloads.
- Optional tenant isolation through a host-provided `TenantScopeProvider`.
- Production commands: `workflow:list`, `workflow:status`, `workflow:history`, and `workflow:diagnose`.
- PHP attribute authoring layer with `#[AsWorkflow]`, `#[Step]`, `#[Action]`, `#[Condition]`, `#[Authorizer]`, `#[Assignee]`, `#[Transition]`, a compiler, a loader, invariants, and `workflow:compile-attributes`.
- Pest, Pest Arch, Larastan/PHPStan, Pint, Testbench, and GitHub Actions quality gates.

### Contract Stability

- The `HFlow\LaravelWorkflow\Contracts\WorkflowEngine` interface is the public API surface.
- Breaking changes to a method signature, return type, or documented exception list require a major version bump.
- Additive changes, such as new methods, new optional parameters with defaults, or new exception subclasses, require a minor version bump.
