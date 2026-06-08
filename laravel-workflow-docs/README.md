# Laravel Workflow Documentation

This directory is the user-facing documentation for `mohamedhabibwork/laravel-workflow`.

Laravel Workflow is a reusable package for versioned workflow definitions, runtime workflow instances, deterministic user actions, synchronous automation, optional tenancy, and append-only history. A workflow can be attached to any Eloquent model through the host application.

## Start Here

| Page | Use it for |
|---|---|
| [Installation](./01-installation.md) | Installing, publishing config/migrations, and running the first smoke test. |
| [Configuration](./02-configuration.md) | Every config key in `config/workflow.php`, including tenancy and automation. |
| [Core Concepts](./03-core-concepts.md) | Workflows, versions, steps, actions, transitions, instances, assignments, and history. |
| [Defining Workflows](./04-defining-workflows.md) | Defining workflows with arrays/Eloquent rows and activating versions. |
| [Workflow Engine API](./05-engine-api.md) | The public `WorkflowEngine` methods and their expected effects. |
| [PHP Attributes](./06-php-attributes.md) | Authoring workflows with native PHP attributes and compiling them to rows. |
| [Authorization And Conditions](./07-authorization-and-conditions.md) | Eligibility, assignees, expression conditions, and custom contracts. |
| [Automation](./08-automation.md) | Automated steps, retries, failures, and chain-depth protection. |
| [History And Activity](./09-history-activity.md) | Append-only history rows, events, activity feed reads, and audit payloads. |
| [Tenancy](./10-tenancy.md) | Tenant scoping, tenant-aware uniqueness, and resolver behavior. |
| [Artisan Commands](./11-artisan-commands.md) | Built-in workflow inspection and attribute compile commands. |
| [Testing And Operations](./12-testing-operations.md) | Pest, PHPStan, Pint, CI, and operational checks for host apps. |
| [Troubleshooting](./13-troubleshooting.md) | Common setup, activation, routing, authorization, compile, and tenancy issues. |

## Reference

| Page | Use it for |
|---|---|
| [BRD](./BRD.md) | Business requirements, guarantees, and non-goals. |
| [ERD](./ERD.md) | Table map, relationships, and schema notes. |
| [State Machines](./STATE_MACHINES.md) | All status values and valid transitions. |

## Design Constraints

- The package does not own the host `users` table.
- Type and status columns are strings cast to PHP enums; there are no lookup tables and no database `ENUM` columns.
- Workflow instances pin the workflow version they started on.
- Every state-changing operation writes append-only history.
- Action availability and user eligibility are re-checked on every `perform()` call.
- Tenancy is optional and supplied by the host through `TenantScopeProvider`.
