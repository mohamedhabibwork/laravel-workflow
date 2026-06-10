# Extension Contracts

The package exposes contracts for custom authorization, condition evaluation, automation, action side effects, and assignee resolution.

## CustomAuthorizer

Use this when a step has `authorization_mode` set to `custom`.

```php
use HFlow\LaravelWorkflow\Contracts\CustomAuthorizer;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;

class ManagerAuthorizer implements CustomAuthorizer
{
    public function authorize(User $user, WorkflowInstance $instance, WorkflowStepInstance $stepInstance): bool
    {
        return $user->can('approve-orders');
    }
}
```

## CustomConditionEvaluator

Use this for conditions with `kind` set to `custom`.

```php
use HFlow\LaravelWorkflow\Contracts\CustomConditionEvaluator;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;

class LargeOrderCondition implements CustomConditionEvaluator
{
    public function evaluate(WorkflowInstance $instance, mixed $subject, array $context, ?User $user = null): bool
    {
        return ($context['amount'] ?? 0) > 1000;
    }
}
```

## StepHandler

Use this for automated steps.

```php
use HFlow\LaravelWorkflow\Contracts\StepHandler;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

class SyncToErp implements StepHandler
{
    public function handle(WorkflowStepInstance $stepInstance, array $context): array
    {
        return ['synced' => true];
    }
}
```

## ActionHandler

Use this to run side effects when an action is accepted.

```php
use HFlow\LaravelWorkflow\Contracts\ActionHandler;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

class NotifyRequester implements ActionHandler
{
    public function handle(WorkflowStepInstance $stepInstance, string $actionCode, array $payload): void
    {
        // Send notification.
    }
}
```

## AssigneeResolver

Use this to resolve explicit user IDs for custom assignment strategies.

```php
use HFlow\LaravelWorkflow\Contracts\AssigneeResolver;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;

class DepartmentManagers implements AssigneeResolver
{
    public function resolve(WorkflowStep $step, WorkflowInstance $instance): array
    {
        return [1, 2, 3];
    }
}
```

All custom classes are resolved through Laravel's service container when used by the engine.

## Runtime Message Contracts

Signals, updates, update validators, queries, and timers are configured on workflow `config` or through PHP attributes.

```php
use HFlow\LaravelWorkflow\Contracts\WorkflowSignalHandler;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;

class PaymentReceivedSignal implements WorkflowSignalHandler
{
    public function handle(WorkflowInstance $instance, string $signal, array $payload = [], ?User $user = null): void
    {
        $instance->update([
            'context' => array_replace($instance->context ?? [], [
                'payment_reference' => $payload['reference'] ?? null,
            ]),
        ]);
    }
}
```

```php
use HFlow\LaravelWorkflow\Contracts\WorkflowUpdateHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowUpdateValidator;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;

class ChangeAddressValidator implements WorkflowUpdateValidator
{
    public function validate(WorkflowInstance $instance, string $update, array $payload = [], ?User $user = null): bool
    {
        return filled($payload['city'] ?? null);
    }
}

class ChangeAddressUpdate implements WorkflowUpdateHandler
{
    public function handle(WorkflowInstance $instance, string $update, array $payload = [], ?User $user = null): array
    {
        return ['shipping_address' => $payload];
    }
}
```

## ActivityHandler

Activities are durable units of external work processed by `workflow:work` or `ActivityService`.

```php
use HFlow\LaravelWorkflow\Contracts\ActivityHandler;
use HFlow\LaravelWorkflow\Models\WorkflowActivity;
use HFlow\LaravelWorkflow\Support\ActivityResult;

class CapturePaymentActivity implements ActivityHandler
{
    public function handle(WorkflowActivity $activity): array|ActivityResult
    {
        return ['payment_id' => 'pay_123'];
    }
}
```

Return `ActivityResult::async()` when an external system will complete the activity later.
