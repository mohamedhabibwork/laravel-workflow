<?php

use HFlow\LaravelWorkflow\Contracts\ActivityHandler;
use HFlow\LaravelWorkflow\Enums\ActivityStatus;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Events\WorkflowHistoryRecorded;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowActivity;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Services\ActivityService;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Support\ActivityResult;
use HFlow\LaravelWorkflow\Tests\Models\TestSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

class CompleteInvoiceActivity implements ActivityHandler
{
    public function handle(WorkflowActivity $activity): array|ActivityResult
    {
        return ['invoice_id' => $activity->input['invoice_id']];
    }
}

class AsyncPaymentActivity implements ActivityHandler
{
    public function handle(WorkflowActivity $activity): array|ActivityResult
    {
        return ActivityResult::async();
    }
}

class FailingActivity implements ActivityHandler
{
    public function handle(WorkflowActivity $activity): array|ActivityResult
    {
        throw new Exception('Activity failed.');
    }
}

function makeActivityInstance(): array
{
    $workflow = Workflow::factory()->active()->create();
    $startStep = WorkflowStep::factory()->start()->create(['workflow_id' => $workflow->id]);
    $workflow->update(['start_step_id' => $startStep->id]);

    $subject = TestSubject::create(['name' => 'Activity Subject']);
    $instance = (new WorkflowEngine)->start($workflow, $subject);

    return [new ActivityService, $instance];
}

test('activities can be scheduled and executed by workers', function () {
    Event::fake([WorkflowHistoryRecorded::class]);
    [$activities, $instance] = makeActivityInstance();

    $activity = $activities->schedule($instance, 'complete-invoice', CompleteInvoiceActivity::class, [
        'invoice_id' => 55,
    ]);

    $processed = $activities->runDue();

    expect($processed)->toHaveCount(1);
    expect($activity->fresh()->status)->toBe(ActivityStatus::Completed);
    expect($activity->fresh()->result['invoice_id'])->toBe(55);
    expect($instance->histories()->where('event', HistoryEvent::ActivityCompleted)->exists())->toBeTrue();

    Event::assertDispatched(WorkflowHistoryRecorded::class);
});

test('activities can wait for asynchronous completion', function () {
    [$activities, $instance] = makeActivityInstance();

    $activity = $activities->schedule($instance, 'async-payment', AsyncPaymentActivity::class);

    $activities->runDue();
    $waiting = $activity->fresh();

    expect($waiting->status)->toBe(ActivityStatus::WaitingForCompletion);
    expect($waiting->async_token)->not->toBeNull();

    $completed = $activities->completeAsync($waiting->async_token, ['paid' => true]);

    expect($completed->status)->toBe(ActivityStatus::Completed);
    expect($completed->result['paid'])->toBeTrue();
});

test('activity failures retry until max attempts then fail', function () {
    [$activities, $instance] = makeActivityInstance();

    $activity = $activities->schedule($instance, 'failing', FailingActivity::class, [], [
        'max_attempts' => 2,
    ]);

    $activities->run($activity);
    expect($activity->fresh()->status)->toBe(ActivityStatus::Pending);

    $activities->run($activity->fresh());
    expect($activity->fresh()->status)->toBe(ActivityStatus::Failed);
    expect($instance->histories()->where('event', HistoryEvent::ActivityFailed)->count())->toBe(2);
});

test('activities can time out', function () {
    [$activities, $instance] = makeActivityInstance();

    $activity = $activities->schedule($instance, 'async-timeout', AsyncPaymentActivity::class, [], [
        'schedule_to_close_timeout_seconds' => 1,
    ]);

    $timedOut = $activities->processTimeouts(now()->addSeconds(2));

    expect($timedOut)->toHaveCount(1);
    expect($activity->fresh()->status)->toBe(ActivityStatus::TimedOut);
});

test('workflow worker command processes activity queues once', function () {
    [$activities, $instance] = makeActivityInstance();

    $activities->schedule($instance, 'complete-invoice', CompleteInvoiceActivity::class, [
        'invoice_id' => 99,
    ], [
        'task_queue' => 'billing',
    ]);

    $this->artisan('workflow:work', [
        '--queue' => 'billing',
        '--once' => true,
    ])->assertSuccessful();

    expect(WorkflowActivity::query()->where('status', ActivityStatus::Completed)->exists())->toBeTrue();
});
