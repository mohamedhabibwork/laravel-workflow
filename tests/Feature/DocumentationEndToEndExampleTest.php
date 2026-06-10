<?php

use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\AvailabilityMode;
use HFlow\LaravelWorkflow\Enums\StepStatus;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Tests\Models\TestSubject;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('documentation end to end approval workflow example runs successfully', function () {
    $workflow = Workflow::create([
        'name' => 'Order Approval',
        'code' => 'order-approval-docs',
        'type' => WorkflowType::Approval,
        'status' => WorkflowStatus::Draft,
    ]);

    $submitted = $workflow->steps()->create([
        'name' => 'Submitted',
        'code' => 'submitted',
        'type' => StepType::Start,
        'position' => 1,
        'authorization_mode' => AuthorizationMode::Public,
    ]);

    $approval = $workflow->steps()->create([
        'name' => 'Manager Approval',
        'code' => 'manager-approval',
        'type' => StepType::Approval,
        'position' => 2,
        'authorization_mode' => AuthorizationMode::Public,
    ]);

    $approved = $workflow->steps()->create([
        'name' => 'Approved',
        'code' => 'approved',
        'type' => StepType::End,
        'position' => 3,
        'authorization_mode' => AuthorizationMode::Public,
    ]);

    $submitted->actions()->create([
        'name' => 'Submit',
        'code' => 'submit',
        'type' => ActionType::Submit,
        'availability_mode' => AvailabilityMode::General,
        'target_step_id' => $approval->id,
    ]);

    $approval->actions()->create([
        'name' => 'Approve',
        'code' => 'approve',
        'type' => ActionType::Approve,
        'availability_mode' => AvailabilityMode::General,
        'target_step_id' => $approved->id,
        'requires_comment' => true,
    ]);

    $workflow->update(['start_step_id' => $submitted->id]);

    LaravelWorkflow::activate($workflow);

    $order = TestSubject::create(['name' => 'Docs Order']);
    $user = new User;
    $user->id = 1;

    $instance = $order->startWorkflow('order-approval-docs', [
        'amount' => 1250,
    ], $user);

    expect($order->workflowActions($user)->pluck('code')->all())->toBe(['submit']);

    $order->performWorkflowAction('submit', $user);

    expect($instance->fresh()->current_step_id)->toBe($approval->id);
    expect($order->workflowActions($user)->pluck('code')->all())->toBe(['approve']);

    $order->performWorkflowAction('approve', $user, [
        'comment' => 'Approved from documentation example.',
    ]);

    $instance->refresh();

    expect($instance->current_step_id)->toBe($approved->id);
    expect($instance->stepInstances()->where('step_id', $approval->id)->first()->status)->toBe(StepStatus::Completed);
    expect($instance->histories()->where('action_code', 'approve')->exists())->toBeTrue();
});
