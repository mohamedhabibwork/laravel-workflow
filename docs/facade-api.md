# Facade API

The package registers a `LaravelWorkflow` facade alias.

```php
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;
```

## Start a Workflow

```php
$instance = LaravelWorkflow::start('order-approval', $order, [
    'amount' => $order->total,
], auth()->user());
```

Start with runtime options:

```php
$instance = LaravelWorkflow::startWithOptions('order-approval', $order, [], auth()->user(), [
    'workflow_identity' => "order-{$order->id}",
    'search_attributes' => ['customer_id' => $order->customer_id],
    'start_delay_seconds' => 300,
    'run_timeout_seconds' => 3600,
]);
```

## Perform an Action

```php
LaravelWorkflow::performAction($instance, 'approve', auth()->user(), [
    'comment' => 'Approved.',
]);
```

## Get Available Actions

```php
$actions = LaravelWorkflow::getAvailableActions($instance, auth()->user());
```

## Activate a Workflow

```php
LaravelWorkflow::activate($workflow);
```

## Runtime Controls

```php
LaravelWorkflow::signal($instance, 'payment-received', ['reference' => 'PAY-1']);
LaravelWorkflow::update($instance, 'change-address', ['city' => 'Cairo']);
LaravelWorkflow::query($instance);
LaravelWorkflow::cancel($instance, 'Customer cancelled.');
LaravelWorkflow::terminate($instance, 'Force stopped.');
LaravelWorkflow::retry($failedInstance);
LaravelWorkflow::continueAsNew($instance, ['page' => 2]);
LaravelWorkflow::startChild($parent, $childWorkflow, $subject);
LaravelWorkflow::scheduleTimer($instance, 'timeout', now()->addHour());
LaravelWorkflow::fireDueTimers();
LaravelWorkflow::processPendingStarts();
LaravelWorkflow::processTimeouts();
LaravelWorkflow::upsertSearchAttributes($instance, ['priority' => 'high']);
LaravelWorkflow::search(['workflow_identity' => 'order-1']);
```

## Activities

```php
$activity = LaravelWorkflow::scheduleActivity($instance, 'capture-payment', CapturePaymentActivity::class, [
    'amount' => 1200,
]);

LaravelWorkflow::runDueActivities('payments');
LaravelWorkflow::completeAsyncActivity($activity->fresh()->async_token, ['payment_id' => 'pay_123']);
```

## Attribute Workflows

```php
LaravelWorkflow::syncAttributes(App\Workflows\OrderApprovalWorkflow::class, activate: true);
LaravelWorkflow::syncConfiguredAttributes(activate: true);
```

## Engine Access

```php
$engine = LaravelWorkflow::getEngine();
$engine = LaravelWorkflow::engine();

LaravelWorkflow::setEngine($customEngine);
LaravelWorkflow::useEngine($customEngine);
```

`setEngine()` and `useEngine()` return the package API instance, so they can be used fluently.
