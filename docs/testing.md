# Testing

Run the package test suite:

```bash
composer test
```

Run Pest directly:

```bash
vendor/bin/pest
```

Run static analysis:

```bash
vendor/bin/phpstan analyse
```

Run formatting:

```bash
vendor/bin/pint --format agent
```

## Test Coverage Areas

The package currently covers:

- Workflow activation validation.
- Workflow version cloning.
- Action resolution.
- Human step advancement.
- Automated step execution.
- Automated failure handling.
- Runtime controls: signals, updates, queries, cancellation, timers, delayed starts, timeouts, termination, continue-as-new, and search attributes.
- PHP attribute workflow sync.
- Activities: execution, retries, timeouts, async completion, and worker command behavior.
- Laravel history event dispatching.
- Public facade engine access.
- Model builder API and helper shortcuts.

## Testing Host Applications

When testing your application workflows:

- Use factories or seeders to create workflow definitions.
- Add `HasWorkflow` to a test subject model.
- Run package migrations in the test database.
- Assert workflow instance status, current step, available actions, and history rows.
- Use `workflow:work --once` to test worker behavior without a long-running process.
- Fake `WorkflowHistoryRecorded` when testing Laravel event listeners.

```php
use HFlow\LaravelWorkflow\Events\WorkflowHistoryRecorded;
use Illuminate\Support\Facades\Event;

Event::fake([WorkflowHistoryRecorded::class]);

// Execute workflow operation...

Event::assertDispatched(WorkflowHistoryRecorded::class);
```
