# End-to-End Example

This example creates a complete order approval workflow, activates it, starts it for an Eloquent model, performs actions, and reads history.

## 1. Prepare the Model

```php
use HFlow\LaravelWorkflow\Traits\HasWorkflow;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasWorkflow;
}
```

## 2. Define the Workflow

```php
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\AvailabilityMode;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;
use HFlow\LaravelWorkflow\Models\Workflow;

$workflow = Workflow::create([
    'name' => 'Order Approval',
    'code' => 'order-approval',
    'type' => WorkflowType::Approval,
    'status' => WorkflowStatus::Draft,
]);

$submitted = $workflow->steps()->create([
    'name' => 'Submitted',
    'code' => 'submitted',
    'type' => StepType::Start,
    'position' => 1,
    'authorization_mode' => AuthorizationMode::Public,
]);

$approval = $workflow->steps()->create([
    'name' => 'Manager Approval',
    'code' => 'manager-approval',
    'type' => StepType::Approval,
    'position' => 2,
    'authorization_mode' => AuthorizationMode::Public,
]);

$approved = $workflow->steps()->create([
    'name' => 'Approved',
    'code' => 'approved',
    'type' => StepType::End,
    'position' => 3,
    'authorization_mode' => AuthorizationMode::Public,
]);

$submitted->actions()->create([
    'name' => 'Submit',
    'code' => 'submit',
    'type' => ActionType::Submit,
    'availability_mode' => AvailabilityMode::General,
    'target_step_id' => $approval->id,
]);

$approval->actions()->create([
    'name' => 'Approve',
    'code' => 'approve',
    'type' => ActionType::Approve,
    'availability_mode' => AvailabilityMode::General,
    'target_step_id' => $approved->id,
    'requires_comment' => true,
]);

$workflow->update(['start_step_id' => $submitted->id]);

LaravelWorkflow::activate($workflow);
```

## 3. Start the Workflow

```php
$instance = $order->startWorkflow('order-approval', [
    'amount' => $order->total,
], auth()->user());
```

## 4. Check Available Actions

```php
$actions = $order->workflowActions(auth()->user());

// submit
$actionCodes = $actions->pluck('code');
```

## 5. Perform the Submit Action

```php
$order->performWorkflowAction('submit', auth()->user());

$instance->refresh();
```

The workflow is now on the manager approval step.

## 6. Perform the Approve Action

```php
$order->performWorkflowAction('approve', auth()->user(), [
    'comment' => 'Approved.',
]);

$instance->refresh();
```

The workflow enters the end step and is completed.

## 7. Read History

```php
$history = $instance->histories()
    ->orderBy('performed_at')
    ->get();
```

## Fluent Builder Variant

```php
$builder = $order->workflow();

$instance = $builder->start('order-approval', [
    'amount' => $order->total,
], auth()->user());

$builder->performAction('submit', auth()->user());

$builder->performAction('approve', auth()->user(), [
    'comment' => 'Approved.',
]);
```

## Tested Example

This flow is covered by `tests/Feature/DocumentationEndToEndExampleTest.php` so the documentation example stays aligned with package behavior.
