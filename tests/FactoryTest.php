<?php

declare(strict_types=1);

namespace Tests;

use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowAssignment;
use HFlow\LaravelWorkflow\Models\WorkflowCondition;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepAssignee;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;

beforeEach(function (): void {
    $this->loadWorkflowMigrations();
});

it('creates a workflow via factory with default state', function (): void {
    $workflow = Workflow::factory()->create();

    expect($workflow)
        ->toBeInstanceOf(Workflow::class)
        ->and($workflow->status)->toBe(WorkflowStatus::Draft)
        ->and($workflow->version)->toBe(1)
        ->and($workflow->is_current_version)->toBeTrue()
        ->and($workflow->uuid)->not->toBeEmpty();
});

it('creates an active workflow via factory state', function (): void {
    $workflow = Workflow::factory()->active()->create();

    expect($workflow->status)->toBe(WorkflowStatus::Active);
});

it('creates a workflow step via factory', function (): void {
    $step = WorkflowStep::factory()->start()->create();

    expect($step->type->value)->toBe('start');
});

it('creates a workflow step assignee via factory', function (): void {
    $assignee = WorkflowStepAssignee::factory()->role()->create();

    expect($assignee->assignee_type->value)->toBe('role');
});

it('creates a workflow step action via factory', function (): void {
    $action = WorkflowStepAction::factory()->requiresComment()->create();

    expect($action->requires_comment)->toBeTrue();
});

it('creates a workflow condition via factory', function (): void {
    $condition = WorkflowCondition::factory()->create();

    expect($condition->kind->value)->toBe('expression')
        ->and($condition->expression)->toBeArray();
});

it('creates a workflow transition via factory', function (): void {
    $transition = WorkflowTransition::factory()->create();

    expect($transition->priority)->toBe(0);
});

it('creates a workflow instance via factory with default state', function (): void {
    $instance = WorkflowInstance::factory()->create();

    expect($instance->status)->toBe(InstanceStatus::InProgress)
        ->and($instance->started_at)->not->toBeNull()
        ->and($instance->workflow_version)->toBe(1);
});

it('creates a completed workflow instance via factory state', function (): void {
    $instance = WorkflowInstance::factory()->completed()->create();

    expect($instance->status)->toBe(InstanceStatus::Completed)
        ->and($instance->completed_at)->not->toBeNull();
});

it('creates a workflow step instance via factory with default state', function (): void {
    $stepInstance = WorkflowStepInstance::factory()->create();

    expect($stepInstance->status)->toBe(StepInstanceStatus::Active)
        ->and($stepInstance->entered_at)->not->toBeNull();
});

it('creates a workflow assignment via factory', function (): void {
    $assignment = WorkflowAssignment::factory()->acted()->create();

    expect($assignment->status->value)->toBe('acted')
        ->and($assignment->acted_at)->not->toBeNull();
});

it('creates a workflow history with default system actor and started event', function (): void {
    $history = WorkflowHistory::factory()->create();

    expect($history->actor_type)->toBe(ActorType::System)
        ->and($history->event)->toBe(HistoryEvent::Started);
});

it('creates a workflow history with custom action and actor', function (): void {
    $history = WorkflowHistory::factory()->actionPerformed('approve', 42)->create();

    expect($history->event)->toBe(HistoryEvent::ActionPerformed)
        ->and($history->action_code)->toBe('approve')
        ->and($history->actor_id)->toBe(42)
        ->and($history->actor_type)->toBe(ActorType::User);
});
