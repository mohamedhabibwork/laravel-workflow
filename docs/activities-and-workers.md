# Activities And Workers

Activities are durable units of external work. They are persisted in `workflow_activities`, processed by workers, retried on failure, and can complete synchronously or asynchronously.

## Activity Handler

```php
namespace App\WorkflowActivities;

use HFlow\LaravelWorkflow\Contracts\ActivityHandler;
use HFlow\LaravelWorkflow\Models\WorkflowActivity;
use HFlow\LaravelWorkflow\Support\ActivityResult;

class CapturePaymentActivity implements ActivityHandler
{
    public function handle(WorkflowActivity $activity): array|ActivityResult
    {
        $amount = $activity->input['amount'];

        return [
            'payment_id' => 'pay_123',
            'amount' => $amount,
        ];
    }
}
```

## Schedule An Activity

```php
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;

$activity = LaravelWorkflow::scheduleActivity($instance, 'capture-payment', CapturePaymentActivity::class, [
    'amount' => 1200,
], [
    'task_queue' => 'payments',
    'max_attempts' => 3,
    'schedule_to_close_timeout_seconds' => 300,
    'start_to_close_timeout_seconds' => 60,
]);
```

Options:

- `step_instance_id`: link to the current runtime step.
- `task_queue`: worker queue name.
- `max_attempts`: retry attempts before final failure.
- `available_at`: delay before worker can pick it up.
- `schedule_to_close_timeout_seconds`: total timeout from scheduling.
- `start_to_close_timeout_seconds`: timeout for execution.

## Execute Activities

Run due activities in code:

```php
LaravelWorkflow::runDueActivities('payments');
```

Or use a worker command:

```bash
php artisan workflow:work --queue=payments
```

For scheduler-based processing:

```bash
php artisan workflow:run-due
```

## Async Completion

Return `ActivityResult::async()` when the activity cannot finish during worker execution.

```php
class BankTransferActivity implements ActivityHandler
{
    public function handle(WorkflowActivity $activity): array|ActivityResult
    {
        // Send request to bank and store $activity->async_token after run starts.

        return ActivityResult::async();
    }
}
```

After the worker runs the activity, it moves to `waiting_for_completion` and receives an `async_token`.

Complete it later:

```php
LaravelWorkflow::completeAsyncActivity($activity->fresh()->async_token, [
    'transfer_id' => 'tr_123',
]);
```

## Timeouts And Retries

Activity statuses:

- `pending`
- `running`
- `waiting_for_completion`
- `completed`
- `failed`
- `timed_out`
- `cancelled`

Failures retry until `max_attempts` is reached. The retry delay uses `config('workflow.activities.retry_delay_seconds')`.

Activity timeouts are processed by:

```php
app(\HFlow\LaravelWorkflow\Services\ActivityService::class)->processTimeouts();
```

or by:

```bash
php artisan workflow:run-due
php artisan workflow:work --once
```

## Worker Processes

Use `workflow:work` for a long-running process:

```bash
php artisan workflow:work --queue=payments --limit=50 --sleep=1
```

Use `--once` for tests or one-shot workers:

```bash
php artisan workflow:work --queue=payments --once
```

The worker processes:

- delayed workflow starts
- due workflow timers
- due activities
- workflow timeouts
- activity timeouts

