<?php

use HFlow\LaravelWorkflow\Contracts\StepHandler;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepStatus;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Tests\Models\TestSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class FailingStepHandler implements StepHandler
{
    public function handle(WorkflowStepInstance $stepInstance, array $context): array
    {
        throw new Exception('Handler failed intentionally');
    }
}

beforeEach(function () {
    $this->engine = new WorkflowEngine;
});

test('failed automated steps set instance and step to failed', function () {
    $workflow = Workflow::factory()->active()->create();

    $startStep = WorkflowStep::factory()->start()->create([
        'workflow_id' => $workflow,
        'position' => 1,
    ]);

    $automatedStep = WorkflowStep::factory()->automated()->create([
        'workflow_id' => $workflow,
        'handler' => FailingStepHandler::class,
        'position' => 2,
    ]);

    $workflow->update(['start_step_id' => $startStep->id]);

    $subject = TestSubject::create(['name' => 'Failing Job']);

    $instance = $this->engine->start($workflow, $subject);

    WorkflowStepAction::factory()->create([
        'step_id' => $startStep->id,
        'code' => 'submit',
    ]);

    $this->engine->performAction($instance, 'submit');

    expect($instance->fresh()->status)->toBe(InstanceStatus::Failed);

    $automatedInstance = $instance->stepInstances()->where('step_id', $automatedStep->id)->first();
    expect($automatedInstance->status)->toBe(StepStatus::Failed);
});
