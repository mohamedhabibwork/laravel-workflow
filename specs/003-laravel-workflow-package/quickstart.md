# Quickstart: Laravel Workflow Package

## 1. Installation

```bash
composer require laravel-workflow/package
php artisan migrate
```

## 2. Prepare Your Model

Add the `HasWorkflow` trait to any model you want to drive through a workflow.

```php
use LaravelWorkflow\Traits\HasWorkflow;

class Order extends Model
{
    use HasWorkflow;
}
```

## 3. Define a Workflow

You can define workflows via the database or using a seeder.

```php
$workflow = Workflow::create([
    'name' => 'Order Approval',
    'code' => 'order-approval',
    'type' => WorkflowType::Approval,
    'status' => WorkflowStatus::Active,
]);

$start = $workflow->steps()->create([
    'name' => 'Draft',
    'code' => 'draft',
    'type' => StepType::Start,
    'position' => 1,
]);

$approval = $workflow->steps()->create([
    'name' => 'Manager Approval',
    'code' => 'manager-approval',
    'type' => StepType::Approval,
    'authorization_mode' => AuthorizationMode::Roles,
    'position' => 2,
]);

$approval->assignees()->create([
    'assignee_type' => AssigneeType::Role,
    'assignee_value' => 'manager',
]);

$approval->actions()->create([
    'name' => 'Approve',
    'code' => 'approve',
    'type' => ActionType::Approve,
]);
```

## 4. Start an Instance

```php
$order = Order::find(1);
$instance = $order->startWorkflow('order-approval');
```

## 5. Perform Actions

```php
$user = auth()->user();
$actions = $order->workflow()->getAvailableActions($user);

if ($actions->contains('code', 'approve')) {
    $order->workflow()->performAction('approve', $user, ['comment' => 'Approved by manager.']);
}
```

## 6. Check History

```php
$history = $order->workflow()->getHistory();
```
