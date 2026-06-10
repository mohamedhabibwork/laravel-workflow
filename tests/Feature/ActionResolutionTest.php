<?php

use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Services\ActionResolver;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Tests\Models\TestSubject;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->engine = new WorkflowEngine;
    $this->resolver = new ActionResolver;

    $this->workflow = Workflow::factory()->active()->create();
    $this->startStep = WorkflowStep::factory()->start()->create([
        'workflow_id' => $this->workflow->id,
        'authorization_mode' => AuthorizationMode::Public,
    ]);
    $this->endStep = WorkflowStep::factory()->end()->create(['workflow_id' => $this->workflow->id]);

    $this->workflow->update(['start_step_id' => $this->startStep->id]);

    $this->subject = TestSubject::create(['name' => 'Test Item']);
    $this->user = new User;
    $this->user->id = 1;
});

test('public steps resolve actions for any user', function () {
    WorkflowStepAction::factory()->create([
        'step_id' => $this->startStep->id,
        'code' => 'submit',
        'type' => ActionType::Submit,
    ]);

    $instance = $this->engine->start($this->workflow, $this->subject);

    $actions = $this->resolver->resolve($instance, $this->user);

    expect($actions)->toHaveCount(1);
    expect($actions->first()->code)->toBe('submit');
});

test('restricted steps do not resolve actions for unauthorized users', function () {
    $this->startStep->update(['authorization_mode' => AuthorizationMode::Users]);

    WorkflowStepAction::factory()->create([
        'step_id' => $this->startStep->id,
        'code' => 'submit',
    ]);

    $instance = $this->engine->start($this->workflow, $this->subject);

    $actions = $this->resolver->resolve($instance, $this->user);

    expect($actions)->toBeEmpty();
});
