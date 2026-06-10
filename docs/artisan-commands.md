# Artisan Commands

## List Workflows

```bash
php artisan workflow:list
```

Filter by status:

```bash
php artisan workflow:list --status=active
php artisan workflow:list --status=draft
```

The command shows:

- ID
- Name
- Code
- Version
- Status
- Current version flag

## Check a Workflow

```bash
php artisan workflow:check order-approval
```

This validates that the current version of a workflow can be activated.

The command checks:

- The workflow exists.
- The workflow has exactly one start step.
- The workflow has at least one end step.

If workflow tables are missing, both commands report that package migrations should be run.

## Sync Attribute Workflows

```bash
php artisan workflow:sync-attributes
php artisan workflow:sync-attributes --activate
php artisan workflow:sync-attributes "App\Workflows\OrderApprovalWorkflow" --activate
```

Without a class argument, the command syncs all classes listed in `config('workflow.attributes.workflows')`.

## Process Due Runtime Work

```bash
php artisan workflow:run-due
```

This command processes:

- delayed workflow starts
- due workflow timers
- due workflow activities
- workflow timeouts
- activity timeouts

Use it from Laravel Scheduler:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('workflow:run-due')->everyMinute();
```

## Run A Worker Process

```bash
php artisan workflow:work
php artisan workflow:work --queue=payments
php artisan workflow:work --queue=payments --once
php artisan workflow:work --queue=payments --limit=100 --sleep=2
```

The worker loop processes delayed starts, timers, activities, workflow timeouts, and activity timeouts. Use `--once` in tests, CI, or short-lived process managers. Use the long-running mode under Supervisor, systemd, Laravel Sail, or another process manager.
