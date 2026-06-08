<?php

declare(strict_types=1);

namespace Workbench\App\Workflows;

use HFlow\LaravelWorkflow\Attributes\Action;
use HFlow\LaravelWorkflow\Attributes\Assignee;
use HFlow\LaravelWorkflow\Attributes\AsWorkflow;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Transition;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AssigneeType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use Workbench\App\Models\Order;

#[AsWorkflow(code: 'attribute_order_approval', name: 'Attribute Order Approval', subject: Order::class, type: WorkflowType::Approval)]
final class OrderApprovalWorkflowV2
{
    #[Step(code: 'start', name: 'Start', type: StepType::Start, position: 1)]
    #[Action(code: 'submit', name: 'Submit', type: ActionType::Submit)]
    #[Transition(from: 'start', to: 'manager_review', on: 'submit')]
    public function start(): void {}

    #[Step(code: 'manager_review', name: 'Manager Review', type: StepType::Approval, position: 2, authorization: AuthorizationMode::Public)]
    #[Assignee(type: AssigneeType::Public, value: '*')]
    #[Action(code: 'approve', name: 'Approve', type: ActionType::Approve)]
    #[Action(code: 'reject', name: 'Reject', type: ActionType::Reject, requiresComment: true, targetStep: 'rejected')]
    #[Action(code: 'escalate', name: 'Escalate', type: ActionType::Custom, targetStep: 'rejected')]
    #[Transition(from: 'manager_review', to: 'approved', on: 'approve')]
    #[Transition(from: 'manager_review', to: 'rejected', on: 'reject')]
    #[Transition(from: 'manager_review', to: 'rejected', on: 'escalate')]
    public function managerReview(): void {}

    #[Step(code: 'approved', name: 'Approved', type: StepType::End, position: 3)]
    public function approved(): void {}

    #[Step(code: 'rejected', name: 'Rejected', type: StepType::End, position: 4)]
    public function rejected(): void {}
}
