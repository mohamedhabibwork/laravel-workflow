# Implementation Plan: Laravel Workflow Package

**Branch**: `003-laravel-workflow-package` | **Date**: 2026-06-09 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/003-laravel-workflow-package/spec.md`

## Summary

Implement a generic, reusable Laravel workflow engine supporting automation pipelines, approval workflows, and free-form state machines. The package will provide versioned blueprints, polymorphic subject support, and an immutable audit trail, adhering to Laravel best practices and avoiding database-level ENUMs.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 13)
**Primary Dependencies**: `laravel/framework`, `laravel/boost`, `laravel/mcp`, `pestphp/pest`
**Storage**: PostgreSQL/MySQL (via Eloquent), JSON columns for context/config
**Testing**: Pest (Feature and Unit tests)
**Target Platform**: Laravel 13 Application Environment
**Project Type**: Laravel Package (Library)
**Performance Goals**: < 100ms for action resolution, support 10,000+ concurrent instances
**Constraints**: No database ENUMs, no lookup tables for status/type, soft deletes + full audit columns
**Scale/Scope**: Reusable engine for any Laravel model, multi-tenancy support

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Requirement | Status |
|-----------|-------------|--------|
| PHP Version | MUST use PHP 8.4 features (Constructor promotion, etc.) | PASS |
| Laravel Way | MUST use `php artisan make:` and follow conventions | PASS |
| Testing | MUST use Pest, focus on Feature tests | PASS |
| Formatting | MUST use Laravel Pint | PASS |
| State Machines | MUST use PHP Enums, NO database ENUMs | PASS |
| Contracts | Extension points MUST use Interfaces (Phase 1 verified) | PASS |
| Data Model | MUST follow audit and soft-delete conventions (Phase 1 verified) | PASS |

## Project Structure

### Documentation (this feature)

```text
specs/003-laravel-workflow-package/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
└── tasks.md             # Phase 2 output
```

### Source Code (repository root)

```text
src/
├── Commands/            # Artisan commands
├── Contracts/           # Interfaces for custom resolvers/authorizers
├── Enums/               # PHP Enums for status/types
├── Facades/             # Package facade
├── Models/              # Workflow, Step, Action, Instance, etc.
├── Services/            # Engine logic, resolution service
├── Traits/              # HasWorkflow trait for host models
└── LaravelWorkflowServiceProvider.php

database/
├── migrations/          # Package migrations
└── factories/           # Model factories for testing

tests/
├── Feature/             # Engine execution, advanced routing
└── Unit/                # Condition evaluation, auth resolution
```

**Structure Decision**: Standard Laravel package structure expanded for workflow-specific needs (Enums, Contracts, Services).

## Complexity Tracking

*No constitution violations identified.*
