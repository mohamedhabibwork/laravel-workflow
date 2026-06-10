# Model Usage and Builder API

Add `HasWorkflow` to any Eloquent model that should be a workflow subject.

```php
use HFlow\LaravelWorkflow\Traits\HasWorkflow;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasWorkflow;
}
```

## Relationships

```php
$order->workflowInstances();
$order->currentWorkflowInstance();
```

`currentWorkflowInstance()` returns the latest instance with one of these statuses:

- `pending`
- `in_progress`
- `on_hold`
- `failed`

## Start a Workflow

```php
$instance = $order->startWorkflow('order-approval', [
    'amount' => $order->total,
], auth()->user());
```

## Get Available Actions

```php
$actions = $order->workflowActions(auth()->user());
```

## Perform an Action

```php
$order->performWorkflowAction('approve', auth()->user(), [
    'comment' => 'Approved by manager.',
]);
```

Start with runtime options:

```php
$instance = $order->startWorkflowWithOptions('order-approval', [
    'amount' => $order->total,
], auth()->user(), [
    'workflow_identity' => "order-{$order->id}",
    'search_attributes' => ['customer_id' => $order->customer_id],
]);
```

## Fluent Builder

```php
$builder = $order->workflow();

$instance = $builder->start('order-approval', [
    'amount' => $order->total,
], auth()->user());

$actions = $builder->availableActions(auth()->user());

$builder->performAction('approve', auth()->user(), [
    'comment' => 'Approved.',
]);
```

Runtime controls are also available from the builder:

```php
$builder->signal('payment-received', ['reference' => 'PAY-1']);
$builder->update('change-address', ['city' => 'Cairo']);
$builder->query();
$builder->cancel('Customer cancelled.');
$builder->terminate('Force stopped.');
$builder->retry();
$builder->continueAsNew(['attempt' => 2]);
$builder->scheduleTimer('timeout', now()->addHour());
$builder->upsertSearchAttributes(['priority' => 'high']);
```

## Builder Methods

```php
$order->workflow()->subject();
$order->workflow()->current();
$order->workflow()->instance();
$order->workflow()->forInstance($instance);
$order->workflow()->start($code, $context, $user);
$order->workflow()->startWithOptions($code, $context, $user, $options);
$order->workflow()->availableActions($user);
$order->workflow()->performAction($code, $user, $payload);
```

## Engine Access

```php
$engine = $order->workflowEngine();

$order->setWorkflowEngine($customEngine);
$order->useWorkflowEngine($customEngine);

$order->workflow()->engine();
$order->workflow()->setEngine($customEngine);
```
