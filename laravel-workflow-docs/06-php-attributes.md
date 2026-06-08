# PHP Attributes

The attribute layer lets hosts write workflow definitions as PHP classes and compile them into the same database rows the runtime engine already reads.

## Configure Paths

```php
'attribute_paths' => [
    'app/Workflows',
],
```

## Example

```php
namespace App\Workflows;

use App\Models\Order;
use HFlow\LaravelWorkflow\Attributes\Action;
use HFlow\LaravelWorkflow\Attributes\AsWorkflow;
use HFlow\LaravelWorkflow\Attributes\Assignee;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Transition;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AssigneeType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\StepType;

#[AsWorkflow(code: 'order-approval', name: 'Order Approval', subject: Order::class, type: 'approval')]
#[Transition(from: 'submitted', to: 'review', on: 'submit')]
#[Transition(from: 'review', to: 'approved', on: 'approve')]
#[Transition(from: 'review', to: 'rejected', on: 'reject')]
final class OrderApprovalWorkflow
{
    #[Step(code: 'submitted', name: 'Submitted', type: StepType::Start, position: 1)]
    #[Action(code: 'submit', name: 'Submit', type: ActionType::Submit, targetStep: 'review')]
    public function submitted(): void {}

    #[Step(code: 'review', name: 'Manager Review', type: StepType::Approval, position: 2, authorization: AuthorizationMode::Roles)]
    #[Assignee(type: AssigneeType::Role, value: 'manager')]
    #[Action(code: 'approve', name: 'Approve', type: ActionType::Approve, targetStep: 'approved')]
    #[Action(code: 'reject', name: 'Reject', type: ActionType::Reject, targetStep: 'rejected', requiresComment: true)]
    public function review(): void {}

    #[Step(code: 'approved', name: 'Approved', type: StepType::End, position: 3)]
    public function approved(): void {}

    #[Step(code: 'rejected', name: 'Rejected', type: StepType::End, position: 4)]
    public function rejected(): void {}
}
```

## Compile

```bash
php artisan workflow:compile-attributes --path=app/Workflows
php artisan workflow:compile-attributes --dry-run
php artisan workflow:compile-attributes --tenant=10
php artisan workflow:compile-attributes --workflow-version=2
```

Options:

| Option | Purpose |
|---|---|
| `--path=` | Restrict compile to a file or directory. |
| `--dry-run` | Validate and report without writing rows. |
| `--strict` | Fail on validation warnings. |
| `--no-strict` | Continue when validation only emits warnings. |
| `--tenant=` | Scope compiled rows to a tenant id. |
| `--workflow-version=` | Force a specific workflow version. |

## Versioning And Idempotency

The compiler stores an `attribute_fingerprint` in workflow config.

- Re-running an unchanged compile keeps the same version and row counts.
- Changing the attribute definition creates the next version.
- Old versions remain available for live instances.

## Compile Validation

The compiler checks invariants before writing rows:

- exactly one start step
- at least one end step
- unique step codes
- transitions reference known steps
- transition action codes exist on the `from` step
- reject actions that require comments are configured safely
- FQCN references exist where required
- tenant id is present when tenancy requires it

Validation failures exit non-zero and roll back the transaction.

