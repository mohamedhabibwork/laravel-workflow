<?php

use HFlow\LaravelWorkflow\Attributes\Action;
use HFlow\LaravelWorkflow\Attributes\Signal;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Transition;
use HFlow\LaravelWorkflow\Attributes\WorkflowDefinition;
use HFlow\LaravelWorkflow\Contracts\WorkflowSignalHandler;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepStatus;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Services\AttributeWorkflowRegistrar;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Services\WorkflowService;
use HFlow\LaravelWorkflow\Tests\Models\TestSubject;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class AttributedRuntimeSignalHandler implements WorkflowSignalHandler
{
    public function handle(WorkflowInstance $instance, string $signal, array $payload = [], ?User $user = null): void
    {
        $context = $instance->context ?? [];
        $context['last_signal'] = $signal;

        $instance->update(['context' => $context]);
    }
}

#[WorkflowDefinition(
    code: 'attribute-order-approval',
    name: 'Attribute Order Approval',
    type: WorkflowType::Approval,
    activate: true,
)]
#[Signal('payment-received', AttributedRuntimeSignalHandler::class)]
#[Step('start', 'Submitted', StepType::Start, position: 1)]
#[Step('manager-review', 'Manager Review', StepType::Approval, position: 2)]
#[Step('approved', 'Approved', StepType::End, position: 3)]
#[Action(
    step: 'start',
    code: 'submit',
    name: 'Submit',
    type: ActionType::Submit,
    targetStep: 'manager-review',
)]
#[Action(
    step: 'manager-review',
    code: 'approve',
    name: 'Approve',
    type: ActionType::Approve,
    targetStep: 'approved',
    requiresComment: true,
)]
#[Transition('start', 'manager-review', action: 'submit')]
#[Transition('manager-review', 'approved', action: 'approve')]
class AttributeOrderApprovalWorkflow {}

test('an attributed workflow can be synced activated and executed end to end', function () {
    $registrar = new AttributeWorkflowRegistrar(new WorkflowService);

    $workflow = $registrar->sync(AttributeOrderApprovalWorkflow::class);

    expect($workflow->status)->toBe(WorkflowStatus::Active);
    expect($workflow->steps)->toHaveCount(3);
    expect($workflow->transitions)->toHaveCount(2);
    expect($workflow->config['signals']['payment-received'])->toBe(AttributedRuntimeSignalHandler::class);

    $subject = TestSubject::create(['name' => 'Attribute Order']);
    $engine = new WorkflowEngine;

    $instance = $engine->start($workflow, $subject, ['amount' => 500]);
    $engine->signal($instance, 'payment-received', ['reference' => 'PAY-1']);
    $engine->performAction($instance, 'submit');
    $engine->performAction($instance->fresh(), 'approve', null, ['comment' => 'Approved.']);

    expect($instance->fresh()->status)->toBe(InstanceStatus::Completed);
    expect($instance->fresh()->histories()->where('event', HistoryEvent::SignalReceived)->exists())->toBeTrue();
    expect($instance->fresh()->stepInstances()->where('status', StepStatus::Completed)->count())->toBe(3);
});

test('configured attributed workflows can be synced by the registrar', function () {
    config()->set('workflow.attributes.workflows', [
        AttributeOrderApprovalWorkflow::class,
    ]);

    $workflows = app(AttributeWorkflowRegistrar::class)->syncConfigured();

    expect($workflows)->toHaveKey(AttributeOrderApprovalWorkflow::class);
    expect(Workflow::query()->where('code', 'attribute-order-approval')->exists())->toBeTrue();
});
