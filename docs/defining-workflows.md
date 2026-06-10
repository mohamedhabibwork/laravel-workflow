# Defining Workflows

Workflow definitions are stored in the database. You can create them from seeders, admin screens, factories, or application code.

For PHP-first declarations, see [PHP Attribute Workflows](attribute-workflows.md).

## Minimal Approval Workflow

```php
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\AvailabilityMode;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Models\Workflow;

$workflow = Workflow::create([
    'name' => 'Order Approval',
    'code' => 'order-approval',
    'type' => WorkflowType::Approval,
    'status' => WorkflowStatus::Draft,
]);

$start = $workflow->steps()->create([
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
    'authorization_mode' => AuthorizationMode::Roles,
]);

$end = $workflow->steps()->create([
    'name' => 'Approved',
    'code' => 'approved',
    'type' => StepType::End,
    'position' => 3,
    'authorization_mode' => AuthorizationMode::Public,
]);

$start->actions()->create([
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
    'target_step_id' => $end->id,
    'requires_comment' => true,
]);

$workflow->update(['start_step_id' => $start->id]);
```

## Activation

Activate a workflow through the facade target or service:

```php
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;

LaravelWorkflow::activate($workflow);
```

Activation requires:

- Exactly one `start` step.
- At least one `end` step.

When a workflow is activated, other workflow rows with the same `code` are marked as not current.

## Versioning

Use `WorkflowService::createNewVersion()` to clone a workflow definition into a draft version:

```php
use HFlow\LaravelWorkflow\Services\WorkflowService;

$draft = app(WorkflowService::class)->createNewVersion($workflow);
```

The service clones steps, actions, assignees, transitions, and action targets into the new version.
