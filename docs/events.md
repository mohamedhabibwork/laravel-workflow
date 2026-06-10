# Laravel Events

The package dispatches a Laravel event whenever immutable workflow history is recorded.

## WorkflowHistoryRecorded

```php
use HFlow\LaravelWorkflow\Events\WorkflowHistoryRecorded;
use Illuminate\Support\Facades\Event;

Event::listen(WorkflowHistoryRecorded::class, function (WorkflowHistoryRecorded $event) {
    logger()->info('Workflow history recorded', [
        'workflow_instance_id' => $event->history->workflow_instance_id,
        'event' => $event->history->event->value,
    ]);
});
```

The event includes the `WorkflowHistory` model.

History is emitted for workflow lifecycle events, messages, timers, activity events, cancellation, termination, timeouts, retries, child starts, and continue-as-new.

## Common Event Values

Workflow events include:

- `started`
- `start_delayed`
- `step_entered`
- `action_performed`
- `completed`
- `cancelled`
- `terminated`
- `timed_out`
- `signal_received`
- `update_accepted`
- `update_rejected`
- `timer_scheduled`
- `timer_fired`
- `child_started`
- `continued_as_new`
- `retried`
- `search_attributes_updated`

Activity events include:

- `activity_scheduled`
- `activity_started`
- `activity_waiting`
- `activity_completed`
- `activity_failed`
- `activity_timed_out`

## Use Cases

Use Laravel listeners for:

- audit stream forwarding
- notifications
- metrics
- external search indexing
- debugging dashboards

Listeners should be idempotent because workflow history is append-only and may be replayed into external systems.

