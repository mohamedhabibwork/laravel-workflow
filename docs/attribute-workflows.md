# PHP Attribute Workflows

PHP attributes let you define workflow blueprints in code and sync them into the package tables.

## Define A Workflow

```php
namespace App\Workflows;

use HFlow\LaravelWorkflow\Attributes\Action;
use HFlow\LaravelWorkflow\Attributes\Signal;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Timer;
use HFlow\LaravelWorkflow\Attributes\Transition;
use HFlow\LaravelWorkflow\Attributes\WorkflowDefinition;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowType;

#[WorkflowDefinition(
    code: 'order-approval',
    name: 'Order Approval',
    type: WorkflowType::Approval,
    version: 1,
    activate: true,
)]
#[Signal('payment-received', PaymentReceivedSignal::class)]
#[Timer('approval-timeout', ApprovalTimeoutTimer::class)]
#[Step('start', 'Submitted', StepType::Start, position: 1)]
#[Step('review', 'Manager Review', StepType::Approval, position: 2)]
#[Step('approved', 'Approved', StepType::End, position: 3)]
#[Action(step: 'start', code: 'submit', type: ActionType::Submit, targetStep: 'review')]
#[Action(step: 'review', code: 'approve', type: ActionType::Approve, targetStep: 'approved', requiresComment: true)]
#[Transition('start', 'review', action: 'submit')]
#[Transition('review', 'approved', action: 'approve')]
class OrderApprovalWorkflow
{
}
```

Supported attributes:

- `WorkflowDefinition`
- `Step`
- `Action`
- `Transition`
- `Condition`
- `Signal`
- `Update`
- `Query`
- `Timer`

## Register Attribute Workflows

Add class names to `config/workflow.php`:

```php
'attributes' => [
    'workflows' => [
        App\Workflows\OrderApprovalWorkflow::class,
    ],
],
```

## Sync Definitions

```bash
php artisan workflow:sync-attributes
php artisan workflow:sync-attributes --activate
php artisan workflow:sync-attributes "App\Workflows\OrderApprovalWorkflow" --activate
```

Or sync from code:

```php
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;

$workflow = LaravelWorkflow::syncAttributes(App\Workflows\OrderApprovalWorkflow::class, activate: true);

$workflows = LaravelWorkflow::syncConfiguredAttributes(activate: true);
```

## Sync Behavior

The registrar upserts by workflow `code` and `version`.

It syncs:

- workflow row
- steps
- actions
- conditions
- transitions
- runtime handler config for signals, updates, queries, and timers

When `activate: true` is set on the attribute or passed to sync, the workflow is validated and activated.

