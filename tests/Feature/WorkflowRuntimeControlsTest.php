<?php

use HFlow\LaravelWorkflow\Contracts\WorkflowSignalHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowTimerHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowUpdateHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowUpdateValidator;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepStatus;
use HFlow\LaravelWorkflow\Enums\TimerStatus;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowTimer;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Tests\Models\TestSubject;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class RuntimeSignalHandler implements WorkflowSignalHandler
{
    public function handle(WorkflowInstance $instance, string $signal, array $payload = [], ?User $user = null): void
    {
        $context = $instance->context ?? [];
        $context['last_signal_handled'] = $signal;
        $context['signal_payload'] = $payload;

        $instance->update(['context' => $context]);
    }
}

class RuntimeUpdateValidator implements WorkflowUpdateValidator
{
    public function validate(WorkflowInstance $instance, string $update, array $payload = [], ?User $user = null): bool
    {
        return ($payload['approved'] ?? false) === true;
    }
}

class RuntimeUpdateHandler implements WorkflowUpdateHandler
{
    public function handle(WorkflowInstance $instance, string $update, array $payload = [], ?User $user = null): array
    {
        return [
            'updates' => [
                $update => $payload,
            ],
        ];
    }
}

class RuntimeTimerHandler implements WorkflowTimerHandler
{
    public function handle(WorkflowInstance $instance, WorkflowTimer $timer): void
    {
        $context = $instance->context ?? [];
        $context['timer_fired'] = $timer->name;

        $instance->update(['context' => $context]);
    }
}

function makeRuntimeWorkflow(): array
{
    $workflow = Workflow::factory()->active()->create([
        'config' => [
            'signals' => [
                'payment-received' => RuntimeSignalHandler::class,
            ],
            'update_validators' => [
                'change-address' => RuntimeUpdateValidator::class,
            ],
            'updates' => [
                'change-address' => RuntimeUpdateHandler::class,
            ],
            'timers' => [
                'payment-timeout' => RuntimeTimerHandler::class,
            ],
        ],
    ]);

    $startStep = WorkflowStep::factory()->start()->create([
        'workflow_id' => $workflow->id,
    ]);

    $workflow->update(['start_step_id' => $startStep->id]);
    $subject = TestSubject::create(['name' => 'Runtime Subject']);

    return [new WorkflowEngine, $workflow, $subject];
}

test('signals are recorded in context and history', function () {
    [$engine, $workflow, $subject] = makeRuntimeWorkflow();
    $instance = $engine->start($workflow, $subject);

    $engine->signal($instance, 'payment-received', ['amount' => 1200]);

    expect($instance->fresh()->context['last_signal_handled'])->toBe('payment-received');
    expect($instance->fresh()->histories()->where('event', HistoryEvent::SignalReceived)->exists())->toBeTrue();
});

test('updates are validated before mutating context', function () {
    [$engine, $workflow, $subject] = makeRuntimeWorkflow();
    $instance = $engine->start($workflow, $subject);

    $changes = $engine->update($instance, 'change-address', [
        'approved' => true,
        'city' => 'Cairo',
    ]);

    expect($changes['updates']['change-address']['city'])->toBe('Cairo');
    expect($instance->fresh()->context['updates']['change-address']['city'])->toBe('Cairo');
    expect($instance->fresh()->histories()->where('event', HistoryEvent::UpdateAccepted)->exists())->toBeTrue();
});

test('invalid updates are rejected and audited', function () {
    [$engine, $workflow, $subject] = makeRuntimeWorkflow();
    $instance = $engine->start($workflow, $subject);

    $engine->update($instance, 'change-address', ['approved' => false]);
})->throws(Exception::class, "Update 'change-address' was rejected by its validator.");

test('query returns workflow state without changing history', function () {
    [$engine, $workflow, $subject] = makeRuntimeWorkflow();
    $instance = $engine->start($workflow, $subject);
    $historyCount = $instance->histories()->count();

    $state = $engine->query($instance);

    expect($state['status'])->toBe(InstanceStatus::InProgress->value);
    expect($instance->histories()->count())->toBe($historyCount);
});

test('cancelling closes active steps and marks the instance cancelled', function () {
    [$engine, $workflow, $subject] = makeRuntimeWorkflow();
    $instance = $engine->start($workflow, $subject);

    $engine->cancel($instance, 'Customer withdrew request.');

    expect($instance->fresh()->status)->toBe(InstanceStatus::Cancelled);
    expect($instance->stepInstances()->where('status', StepStatus::Cancelled)->exists())->toBeTrue();
    expect($instance->histories()->where('event', HistoryEvent::Cancelled)->exists())->toBeTrue();
});

test('timers are persisted and fired by due time', function () {
    [$engine, $workflow, $subject] = makeRuntimeWorkflow();
    $instance = $engine->start($workflow, $subject);

    $timer = $engine->scheduleTimer($instance, 'payment-timeout', now()->subSecond());
    $processed = $engine->fireDueTimers();

    expect($processed)->toHaveCount(1);
    expect($timer->fresh()->status)->toBe(TimerStatus::Fired);
    expect($instance->fresh()->context['timer_fired'])->toBe('payment-timeout');
});
