# Runtime Controls

Runtime controls are Laravel-native equivalents for common durable workflow operations.

## Start Options

```php
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;

$instance = LaravelWorkflow::startWithOptions('order-approval', $order, [
    'amount' => $order->total,
], auth()->user(), [
    'workflow_identity' => "order-{$order->id}",
    'task_queue' => 'orders',
    'memo' => ['source' => 'checkout'],
    'search_attributes' => [
        'customer_id' => $order->customer_id,
        'priority' => 'high',
    ],
    'start_delay_seconds' => 300,
    'run_timeout_seconds' => 3600,
    'execution_timeout_seconds' => 86400,
]);
```

Important fields:

- `workflow_identity`: stable identity across runs.
- `run_id`: generated per run unless provided.
- `first_execution_run_id`: preserved across continue-as-new.
- `memo`: non-indexed metadata.
- `search_attributes`: visibility metadata for `search()`.
- `start_delay_seconds` or `start_after`: delayed start.
- `run_timeout_seconds` and `execution_timeout_seconds`: timeout boundaries.

## Messages

Signals mutate workflow state asynchronously:

```php
LaravelWorkflow::signal($instance, 'payment-received', [
    'reference' => 'PAY-123',
]);
```

Updates validate and mutate state:

```php
$changes = LaravelWorkflow::update($instance, 'change-address', [
    'city' => 'Cairo',
]);
```

Queries read state without writing history:

```php
$state = LaravelWorkflow::query($instance);
$summary = LaravelWorkflow::query($instance, 'summary');
```

Handlers are configured in workflow `config`:

```php
$workflow->update([
    'config' => [
        'signals' => [
            'payment-received' => PaymentReceivedSignal::class,
        ],
        'update_validators' => [
            'change-address' => ChangeAddressValidator::class,
        ],
        'updates' => [
            'change-address' => ChangeAddressUpdate::class,
        ],
        'queries' => [
            'summary' => WorkflowSummaryQuery::class,
        ],
    ],
]);
```

## Timers And Delayed Starts

Schedule a timer:

```php
LaravelWorkflow::scheduleTimer($instance, 'payment-timeout', now()->addHour(), [
    'order_id' => $order->id,
]);
```

Process due timers and delayed starts:

```php
LaravelWorkflow::fireDueTimers();
LaravelWorkflow::processPendingStarts();
```

In production, prefer:

```bash
php artisan workflow:run-due
```

## Cancellation, Termination, Retry

```php
LaravelWorkflow::cancel($instance, 'Customer cancelled.');
LaravelWorkflow::terminate($instance, 'Operator force stopped run.');
LaravelWorkflow::retry($failedInstance);
```

Cancellation is cooperative from the package perspective. Termination closes the run immediately and cancels pending timers.

## Child Workflows

```php
$child = LaravelWorkflow::startChild($parentInstance, $childWorkflow, $invoice, [
    'parent_order_id' => $order->id,
]);
```

The child receives `parent_workflow_instance_id` in context and stores `parent_instance_id`.

## Continue As New

```php
$nextRun = LaravelWorkflow::continueAsNew($instance, [
    'page' => 2,
]);
```

The old run is completed. The new run keeps the same `workflow_identity` and `first_execution_run_id`, with a new `run_id`.

## Visibility Search

```php
$runs = LaravelWorkflow::search([
    'workflow_code' => 'order-approval',
    'workflow_identity' => "order-{$order->id}",
    'status' => 'in_progress',
    'task_queue' => 'orders',
    'search_attributes' => [
        'customer_id' => $order->customer_id,
    ],
]);
```

Update search attributes:

```php
LaravelWorkflow::upsertSearchAttributes($instance, [
    'priority' => 'urgent',
]);
```

