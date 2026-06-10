# Automation

Automated steps run a handler class when the workflow enters the step.

Use automated steps for quick, deterministic application work that should happen during workflow routing. Use [Activities and Workers](activities-and-workers.md) for external calls, slow work, retries, async completion, or worker queues.

## Step Handler

Create a handler that implements `StepHandler`:

```php
namespace App\Workflows;

use HFlow\LaravelWorkflow\Contracts\StepHandler;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

class CapturePayment implements StepHandler
{
    public function handle(WorkflowStepInstance $stepInstance, array $context): array
    {
        // Run application work here.

        return [
            'payment_captured' => true,
        ];
    }
}
```

Register the handler class on an automated step:

```php
$payment = $workflow->steps()->create([
    'name' => 'Capture Payment',
    'code' => 'capture-payment',
    'type' => StepType::Automated,
    'position' => 2,
    'handler' => \App\Workflows\CapturePayment::class,
]);
```

Handlers are resolved through Laravel's service container, so constructor dependencies can be injected.

## Automatic Transitions

Create automatic transitions from automated steps:

```php
$workflow->transitions()->create([
    'from_step_id' => $payment->id,
    'to_step_id' => $nextStep->id,
    'type' => TransitionType::Automatic,
    'priority' => 100,
]);
```

The engine evaluates automatic and conditional transitions by highest priority first.

## Failure Handling

If an automated handler throws an exception:

- The step instance is marked `failed`.
- The workflow instance is marked `failed`.
- A history entry is written with the error message.

Retry the latest failed step:

```php
LaravelWorkflow::retry($instance);
```

## Activities For External Work

For external side effects, schedule an activity instead of doing the work directly in an automated step:

```php
LaravelWorkflow::scheduleActivity($instance, 'capture-payment', CapturePaymentActivity::class, [
    'amount' => $context['amount'],
], [
    'task_queue' => 'payments',
    'max_attempts' => 3,
]);
```

Then process activities with:

```bash
php artisan workflow:work --queue=payments
```
