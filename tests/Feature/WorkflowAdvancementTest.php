<?php

use HFlow\LaravelWorkflow\Enums\StepStatus;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Tests\Models\TestSubject;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->engine = new WorkflowEngine;

    $this->workflow = Workflow::factory()->active()->create();
    $this->startStep = WorkflowStep::factory()->start()->create([
        'workflow_id' => $this->workflow->id,
        'position' => 1,
    ]);
    $this->taskStep = WorkflowStep::factory()->create([
        'workflow_id' => $this->workflow->id,
        'type' => StepType::Task,
        'position' => 2,
    ]);
    $this->endStep = WorkflowStep::factory()->end()->create([
        'workflow_id' => $this->workflow->id,
        'position' => 3,
    ]);

    $this->workflow->update(['start_step_id' => $this->startStep->id]);

    $this->subject = TestSubject::create(['name' => 'Test Item']);
    $this->user = new User;
    $this->user->id = 1;
});

test('performing an action advances the workflow to the next step via sequential fallback', function () {
    WorkflowStepAction::factory()->create([
        'step_id' => $this->startStep->id,
        'code' => 'submit',
    ]);

    $instance = $this->engine->start($this->workflow, $this->subject);

    $this->engine->performAction($instance, 'submit', $this->user);

    expect($instance->fresh()->current_step_id)->toBe($this->taskStep->id);
    expect($instance->fresh()->stepInstances()->where('step_id', $this->startStep->id)->first()->status)->toBe(StepStatus::Completed);
});

test('performing an action with an explicit target advances to that target', function () {
    WorkflowStepAction::factory()->create([
        'step_id' => $this->startStep->id,
        'code' => 'jump-to-end',
        'target_step_id' => $this->endStep->id,
    ]);

    $instance = $this->engine->start($this->workflow, $this->subject);

    $this->engine->performAction($instance, 'jump-to-end', $this->user);

    expect($instance->fresh()->current_step_id)->toBe($this->endStep->id);
});

test('actions requiring comments fail without one', function () {
    WorkflowStepAction::factory()->create([
        'step_id' => $this->startStep->id,
        'code' => 'reject',
        'requires_comment' => true,
    ]);

    $instance = $this->engine->start($this->workflow, $this->subject);

    $this->engine->performAction($instance, 'reject', $this->user);
})->throws(Exception::class, "Action 'reject' requires a comment.");
