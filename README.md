# Laravel Workflow

Laravel Workflow is a reusable workflow engine package for Laravel applications. It supports approval flows, automation pipelines, and generic state machines using versioned workflow definitions, Eloquent models, PHP enums, action guards, custom handlers, and immutable history.

## Features

- Versioned workflow definitions with activation validation.
- Eloquent subject integration through the `HasWorkflow` trait.
- Fluent model builder API: `$model->workflow()->start(...)`.
- Facade API: `LaravelWorkflow::start(...)`, `LaravelWorkflow::performAction(...)`.
- Human approval steps with role, permission, user, public, and custom authorization modes.
- Automated steps with container-resolved handlers.
- Conditional and automatic transitions.
- Laravel-native runtime controls inspired by Temporal workflows: signals, validated updates, read-only queries, cancellation, retry, continue-as-new, child workflow starts, and durable timers.
- Immutable workflow history.
- Optional multi-tenancy scope.
- Artisan commands for listing and validating workflows.

## Requirements

- PHP 8.4 or newer.
- Laravel components 11, 12, or 13.
- A database supported by Laravel Eloquent.

## Installation

Install the package:

```bash
composer require mohamedhabibwork/laravel-workflow
```

Publish the config and migrations:

```bash
php artisan vendor:publish --tag="laravel-workflow-config"
php artisan vendor:publish --tag="laravel-workflow-migrations"
php artisan migrate
```

## Quick Start

Add the trait to any model that should run through workflows:

```php
use HFlow\LaravelWorkflow\Traits\HasWorkflow;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasWorkflow;
}
```

Start and advance a workflow from the model:

```php
$instance = $order->startWorkflow('order-approval', [
    'amount' => $order->total,
], auth()->user());

$actions = $order->workflowActions(auth()->user());

$order->performWorkflowAction('approve', auth()->user(), [
    'comment' => 'Approved.',
]);
```

Use the fluent builder when you want a scoped API:

```php
$order->workflow()
    ->start('order-approval', ['amount' => $order->total], auth()->user());

$order->workflow()
    ->performAction('approve', auth()->user(), ['comment' => 'Approved.']);
```

Use the facade for application-level workflows:

```php
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;

$instance = LaravelWorkflow::start('order-approval', $order);

LaravelWorkflow::performAction($instance, 'approve', auth()->user(), [
    'comment' => 'Approved.',
]);
```

## Runtime Controls

Workflow definitions may configure optional container-resolved handlers in their `config` JSON. This keeps orchestration durable in Laravel/Eloquent without requiring Temporal, RoadRunner, or any external worker runtime.

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
        'timers' => [
            'payment-timeout' => PaymentTimeoutTimer::class,
        ],
    ],
]);
```

Use the facade or model builder to interact with a running workflow:

```php
LaravelWorkflow::signal($instance, 'payment-received', ['amount' => 1200]);

$changes = LaravelWorkflow::update($instance, 'change-address', [
    'approved' => true,
    'city' => 'Cairo',
]);

$state = LaravelWorkflow::query($instance);

LaravelWorkflow::scheduleTimer($instance, 'payment-timeout', now()->addHour());
LaravelWorkflow::fireDueTimers();

LaravelWorkflow::cancel($instance, 'Customer withdrew request.');
LaravelWorkflow::retry($failedInstance);
```

Start with workflow identity, memo, search attributes, delayed starts, and timeouts:

```php
$instance = LaravelWorkflow::startWithOptions('order-approval', $order, [
    'amount' => $order->total,
], auth()->user(), [
    'workflow_identity' => "order-{$order->id}",
    'task_queue' => 'orders',
    'memo' => ['source' => 'checkout'],
    'search_attributes' => ['customer_id' => $order->customer_id],
    'start_delay_seconds' => 300,
    'run_timeout_seconds' => 3600,
]);
```

Process delayed starts, due timers, and workflow timeouts from Laravel Scheduler:

```bash
php artisan workflow:run-due
```

```php
Schedule::command('workflow:run-due')->everyMinute();
```

For a long-running worker process, run:

```bash
php artisan workflow:work --queue=orders
```

Visibility-style lookup uses normal database fields and JSON search attributes:

```php
$runs = LaravelWorkflow::search([
    'workflow_identity' => "order-{$order->id}",
    'status' => 'in_progress',
    'search_attributes' => ['customer_id' => $order->customer_id],
]);
```

Temporal-inspired feature mapping in this Laravel package:

- Workflow ID and Run ID: `workflow_identity`, `run_id`, and `first_execution_run_id`.
- Event history: immutable `workflow_histories`.
- Signals, Updates, Queries: `signal()`, `update()`, and `query()` with optional handlers.
- Timers and delayed starts: `scheduleTimer()`, `startWithOptions(... start_delay_seconds ...)`, and `workflow:run-due`.
- Timeouts and retries: `processTimeouts()` and `retry()`.
- Cancellation and termination: `cancel()` and `terminate()`.
- Child workflows and continue-as-new: `startChild()` and `continueAsNew()`.
- Visibility: `memo`, `search_attributes`, and `search()`.

## Laravel Events

Every workflow and activity history entry dispatches a Laravel event:

```php
use HFlow\LaravelWorkflow\Events\WorkflowHistoryRecorded;
use Illuminate\Support\Facades\Event;

Event::listen(WorkflowHistoryRecorded::class, function (WorkflowHistoryRecorded $event) {
    logger()->info('Workflow event recorded', [
        'event' => $event->history->event->value,
        'workflow_instance_id' => $event->history->workflow_instance_id,
    ]);
});
```

## Activities And Workers

Activities are Laravel container-resolved handlers with persisted attempts, results, timeout state, and optional asynchronous completion.

```php
use HFlow\LaravelWorkflow\Contracts\ActivityHandler;
use HFlow\LaravelWorkflow\Models\WorkflowActivity;
use HFlow\LaravelWorkflow\Support\ActivityResult;

class CapturePaymentActivity implements ActivityHandler
{
    public function handle(WorkflowActivity $activity): array|ActivityResult
    {
        // Do external work here.

        return [
            'payment_id' => 'pay_123',
        ];
    }
}
```

Schedule an activity:

```php
$activity = LaravelWorkflow::scheduleActivity($instance, 'capture-payment', CapturePaymentActivity::class, [
    'amount' => 1200,
], [
    'task_queue' => 'payments',
    'max_attempts' => 3,
    'schedule_to_close_timeout_seconds' => 300,
    'start_to_close_timeout_seconds' => 60,
]);
```

Workers execute due activities:

```php
LaravelWorkflow::runDueActivities('payments');
```

For asynchronous completion, return `ActivityResult::async()` from the handler and complete it later with the generated token:

```php
LaravelWorkflow::completeAsyncActivity($activity->fresh()->async_token, [
    'payment_id' => 'pay_123',
]);
```

## PHP Attribute Workflows

You can define a full workflow in PHP and sync it into the package tables:

```php
use HFlow\LaravelWorkflow\Attributes\Action;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Transition;
use HFlow\LaravelWorkflow\Attributes\WorkflowDefinition;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowType;

#[WorkflowDefinition(
    code: 'order-approval',
    name: 'Order Approval',
    type: WorkflowType::Approval,
    activate: true,
)]
#[Step('start', 'Submitted', StepType::Start, position: 1)]
#[Step('review', 'Review', StepType::Approval, position: 2)]
#[Step('approved', 'Approved', StepType::End, position: 3)]
#[Action(step: 'start', code: 'submit', type: ActionType::Submit, targetStep: 'review')]
#[Action(step: 'review', code: 'approve', type: ActionType::Approve, targetStep: 'approved', requiresComment: true)]
#[Transition('start', 'review', action: 'submit')]
#[Transition('review', 'approved', action: 'approve')]
class OrderApprovalWorkflow
{
}
```

Register attributed workflows in `config/workflow.php`:

```php
'attributes' => [
    'workflows' => [
        App\Workflows\OrderApprovalWorkflow::class,
    ],
],
```

Sync them with Artisan:

```bash
php artisan workflow:sync-attributes --activate
```

Or sync a single class in code:

```php
LaravelWorkflow::syncAttributes(App\Workflows\OrderApprovalWorkflow::class, activate: true);
```

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Defining Workflows](docs/defining-workflows.md)
- [PHP Attribute Workflows](docs/attribute-workflows.md)
- [Model Usage and Builder API](docs/model-usage.md)
- [Facade API](docs/facade-api.md)
- [Runtime Controls](docs/runtime-controls.md)
- [Activities and Workers](docs/activities-and-workers.md)
- [Laravel Events](docs/events.md)
- [Customization and Overrides](docs/customization.md)
- [End-to-End Example](docs/end-to-end-example.md)
- [Actions and Conditions](docs/actions-and-conditions.md)
- [Automation](docs/automation.md)
- [Multi-Tenancy](docs/multi-tenancy.md)
- [Artisan Commands](docs/artisan-commands.md)
- [Extension Contracts](docs/extension-contracts.md)
- [Temporal Feature Mapping](docs/temporal-feature-mapping.md)
- [Testing](docs/testing.md)

## Artisan Commands

```bash
php artisan workflow:list
php artisan workflow:list --status=active
php artisan workflow:check order-approval
php artisan workflow:sync-attributes --activate
php artisan workflow:run-due
php artisan workflow:work --queue=orders
```

## Testing

```bash
composer test
vendor/bin/phpstan analyse
vendor/bin/pint --format agent
```

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
