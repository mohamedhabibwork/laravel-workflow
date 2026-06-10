<?php

use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepStatus;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Tests\Models\TestSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeLifecycleWorkflow(): array
{
    $workflow = Workflow::factory()->active()->create();
    $startStep = WorkflowStep::factory()->start()->create([
        'workflow_id' => $workflow->id,
        'position' => 1,
    ]);

    WorkflowStep::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => StepType::Task,
        'position' => 2,
    ]);

    $workflow->update(['start_step_id' => $startStep->id]);
    $subject = TestSubject::create(['name' => 'Lifecycle Subject']);

    return [new WorkflowEngine, $workflow, $subject];
}

test('workflow runs can be started with identity memo search attributes and run ids', function () {
    [$engine, $workflow, $subject] = makeLifecycleWorkflow();

    $instance = $engine->startWithOptions($workflow, $subject, ['amount' => 100], null, [
        'workflow_identity' => 'order-100',
        'memo' => ['note' => 'priority'],
        'search_attributes' => ['customer' => 'ACME'],
        'task_queue' => 'billing',
    ]);

    expect($instance->workflow_identity)->toBe('order-100');
    expect($instance->run_id)->not->toBeNull();
    expect($instance->first_execution_run_id)->toBe($instance->run_id);
    expect($instance->memo['note'])->toBe('priority');
    expect($instance->search_attributes['customer'])->toBe('ACME');
    expect($engine->search(['workflow_identity' => 'order-100']))->toHaveCount(1);
});

test('delayed workflow starts are pending until processed', function () {
    [$engine, $workflow, $subject] = makeLifecycleWorkflow();

    $instance = $engine->startWithOptions($workflow, $subject, [], null, [
        'start_after' => now()->addMinute(),
    ]);

    expect($instance->status)->toBe(InstanceStatus::Pending);
    expect($instance->stepInstances()->count())->toBe(0);
    expect($instance->histories()->where('event', HistoryEvent::StartDelayed)->exists())->toBeTrue();

    expect($engine->processPendingStarts(now()->addMinutes(2)))->toHaveCount(1);
    expect($instance->fresh()->status)->toBe(InstanceStatus::InProgress);
    expect($instance->fresh()->stepInstances()->where('status', StepStatus::Active)->exists())->toBeTrue();
});

test('workflow timeout processing closes active runs', function () {
    [$engine, $workflow, $subject] = makeLifecycleWorkflow();

    $instance = $engine->startWithOptions($workflow, $subject, [], null, [
        'run_timeout_seconds' => 1,
    ]);

    expect($engine->processTimeouts(now()->addSeconds(2)))->toHaveCount(1);
    expect($instance->fresh()->status)->toBe(InstanceStatus::TimedOut);
    expect($instance->fresh()->histories()->where('event', HistoryEvent::TimedOut)->exists())->toBeTrue();
});

test('workflow runs can be terminated immediately', function () {
    [$engine, $workflow, $subject] = makeLifecycleWorkflow();

    $instance = $engine->start($workflow, $subject);

    $engine->terminate($instance, 'Force stopped.');

    expect($instance->fresh()->status)->toBe(InstanceStatus::Terminated);
    expect($instance->fresh()->histories()->where('event', HistoryEvent::Terminated)->exists())->toBeTrue();
});

test('search attributes can be updated and queried', function () {
    [$engine, $workflow, $subject] = makeLifecycleWorkflow();

    $instance = $engine->start($workflow, $subject);
    $engine->upsertSearchAttributes($instance, [
        'customer' => 'ACME',
        'priority' => 'high',
    ]);

    expect($instance->fresh()->search_attributes['priority'])->toBe('high');
    expect($engine->search(['search_attributes' => ['customer' => 'ACME']]))->toHaveCount(1);
    expect($instance->fresh()->histories()->where('event', HistoryEvent::SearchAttributesUpdated)->exists())->toBeTrue();
});

test('continue as new preserves workflow identity and first execution run id', function () {
    [$engine, $workflow, $subject] = makeLifecycleWorkflow();

    $instance = $engine->startWithOptions($workflow, $subject, [], null, [
        'workflow_identity' => 'order-continue',
    ]);

    $next = $engine->continueAsNew($instance, ['attempt' => 2]);

    expect($next->workflow_identity)->toBe('order-continue');
    expect($next->run_id)->not->toBe($instance->run_id);
    expect($next->first_execution_run_id)->toBe($instance->run_id);
    expect($instance->fresh()->histories()->where('event', HistoryEvent::ContinuedAsNew)->exists())->toBeTrue();
});
