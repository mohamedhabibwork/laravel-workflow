<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Tests\Fixtures;

use HFlow\LaravelWorkflow\Attributes\AsWorkflow;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Transition;
use HFlow\LaravelWorkflow\Enums\StepType;

#[AsWorkflow(code: 'invalid_attribute_expression', name: 'Invalid Attribute Expression')]
final class InvalidAttributeExpressionWorkflow
{
    #[Step(code: 'start', type: StepType::Start, name: 'Start')]
    #[Transition(from: 'start', to: 'end', on: '', when: 'this is not valid')]
    public function start(): void {}

    #[Step(code: 'end', type: StepType::End, name: 'End')]
    public function end(): void {}
}
