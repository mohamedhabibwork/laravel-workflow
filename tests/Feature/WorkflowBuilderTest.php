<?php

use HFlow\LaravelWorkflow\Enums\ActionType;
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

    WorkflowStep::factory()->end()->create([
        'workflow_id' => $this->workflow->id,
        'position' => 3,
    ]);

    $this->workflow->update(['start_step_id' => $this->startStep->id]);

    $this->action = WorkflowStepAction::factory()->create([
        'step_id' => $this->startStep->id,
        'code' => 'submit',
        'type' => ActionType::Submit,
    ]);

    $this->subject = TestSubject::create(['name' => 'Builder Subject']);
    $this->user = new User;
    $this->user->id = 1;
});

test('a subject model can use the workflow builder to start and advance a workflow', function () {
    $builder = $this->subject->workflow();

    $instance = $builder->start($this->workflow->code, ['source' => 'builder'], $this->user);

    expect($builder->instance()?->is($instance))->toBeTrue();
    expect($this->subject->currentWorkflowInstance()?->is($instance))->toBeTrue();
    expect($builder->availableActions($this->user))->toHaveCount(1);

    $builder->performAction('submit', $this->user);

    expect($instance->fresh()->current_step_id)->toBe($this->taskStep->id);
    expect($instance->fresh()->stepInstances()->where('step_id', $this->startStep->id)->first()->status)->toBe(StepStatus::Completed);
});

test('a subject model exposes workflow helper shortcuts', function () {
    $instance = $this->subject->startWorkflow($this->workflow->code, ['source' => 'helper'], $this->user);

    expect($this->subject->workflowActions($this->user))->toHaveCount(1);

    $this->subject->performWorkflowAction('submit', $this->user);

    expect($instance->fresh()->current_step_id)->toBe($this->taskStep->id);
});

test('a subject model can get and replace the workflow engine used by helpers', function () {
    $replacement = new WorkflowEngine;

    expect($this->subject->workflowEngine())->toBeInstanceOf(WorkflowEngine::class);
    expect($this->subject->setWorkflowEngine($replacement))->toBe($this->subject);
    expect($this->subject->workflowEngine())->toBe($replacement);

    $anotherReplacement = new WorkflowEngine;

    expect($this->subject->useWorkflowEngine($anotherReplacement))->toBe($this->subject);
    expect($this->subject->workflow()->engine())->toBe($anotherReplacement);
});
