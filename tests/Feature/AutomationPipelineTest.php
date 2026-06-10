<?php

use HFlow\LaravelWorkflow\Contracts\StepHandler;
use HFlow\LaravelWorkflow\Enums\StepStatus;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Tests\Models\TestSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class TestStepHandler implements StepHandler
{
    public function handle(WorkflowStepInstance $stepInstance, array $context): array
    {
        return ['processed' => true];
    }
}

beforeEach(function () {
    $this->engine = new WorkflowEngine;
});

test('entering an automated step executes the handler and advances automatically', function () {
    $workflow = Workflow::factory()->active()->create();

    $startStep = WorkflowStep::factory()->start()->create([
        'workflow_id' => $workflow,
        'position' => 1,
    ]);

    $automatedStep = WorkflowStep::factory()->automated()->create([
        'workflow_id' => $workflow,
        'handler' => TestStepHandler::class,
        'position' => 2,
    ]);

    $endStep = WorkflowStep::factory()->end()->create([
        'workflow_id' => $workflow,
        'position' => 3,
    ]);

    $workflow->update(['start_step_id' => $startStep->id]);

    $subject = TestSubject::create(['name' => 'Automated Job']);

    $instance = $this->engine->start($workflow, $subject);

    WorkflowStepAction::factory()->create([
        'step_id' => $startStep->id,
        'code' => 'submit',
    ]);

    $this->engine->performAction($instance, 'submit');

    expect($instance->fresh()->current_step_id)->toBe($endStep->id);

    $automatedInstance = $instance->stepInstances()->where('step_id', $automatedStep->id)->first();
    expect($automatedInstance->status)->toBe(StepStatus::Completed);
    expect($automatedInstance->data)->toBe(['processed' => true]);
});
