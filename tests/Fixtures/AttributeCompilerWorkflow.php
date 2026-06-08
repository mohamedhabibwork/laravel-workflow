<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Tests\Fixtures;

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

#[AsWorkflow(code: 'attribute_fixture', name: 'Attribute Fixture', type: WorkflowType::Approval)]
final class AttributeCompilerWorkflow
{
    #[Step(code: 'start', type: StepType::Start, name: 'Start', position: 1)]
    #[Action(code: 'submit', type: ActionType::Submit, name: 'Submit')]
    #[Transition(from: 'start', to: 'review', on: 'submit')]
    public function start(): void {}

    #[Step(code: 'review', type: StepType::Approval, name: 'Review', position: 2, authorization: AuthorizationMode::Public)]
    #[Assignee(type: AssigneeType::Public, value: '*')]
    #[Action(code: 'approve', type: ActionType::Approve, name: 'Approve', guardCondition: 'context.amount > 100')]
    #[Action(code: 'reject', type: ActionType::Reject, name: 'Reject', requiresComment: true)]
    #[Transition(from: 'review', to: 'approved', on: 'approve', when: 'context.amount >= 100')]
    #[Transition(from: 'review', to: 'rejected', on: 'reject')]
    public function review(): void {}

    #[Step(code: 'approved', type: StepType::End, name: 'Approved', position: 3)]
    public function approved(): void {}

    #[Step(code: 'rejected', type: StepType::End, name: 'Rejected', position: 4)]
    public function rejected(): void {}
}
