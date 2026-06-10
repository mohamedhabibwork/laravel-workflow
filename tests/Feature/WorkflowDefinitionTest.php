<?php

use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new WorkflowService;
});

test('a workflow can be activated if it has exactly one start step and at least one end step', function () {
    $workflow = Workflow::factory()->create();

    WorkflowStep::factory()->start()->create(['workflow_id' => $workflow->id]);
    WorkflowStep::factory()->end()->create(['workflow_id' => $workflow->id]);

    $this->service->activate($workflow);

    expect($workflow->fresh()->status)->toBe(WorkflowStatus::Active);
});

test('activation fails if there is no start step', function () {
    $workflow = Workflow::factory()->create();
    WorkflowStep::factory()->end()->create(['workflow_id' => $workflow->id]);

    $this->service->activate($workflow);
})->throws(Exception::class, 'A workflow must have exactly one start step.');

test('activation fails if there are multiple start steps', function () {
    $workflow = Workflow::factory()->create();
    WorkflowStep::factory()->start()->create(['workflow_id' => $workflow->id]);
    WorkflowStep::factory()->start()->create(['workflow_id' => $workflow->id]);
    WorkflowStep::factory()->end()->create(['workflow_id' => $workflow->id]);

    $this->service->activate($workflow);
})->throws(Exception::class, 'A workflow must have exactly one start step.');

test('activation fails if there is no end step', function () {
    $workflow = Workflow::factory()->create();
    WorkflowStep::factory()->start()->create(['workflow_id' => $workflow->id]);

    $this->service->activate($workflow);
})->throws(Exception::class, 'A workflow must have at least one end step.');
