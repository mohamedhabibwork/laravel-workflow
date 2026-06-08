<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Attributes\Action;
use HFlow\LaravelWorkflow\Attributes\Assignee;
use HFlow\LaravelWorkflow\Attributes\AsWorkflow;
use HFlow\LaravelWorkflow\Attributes\Authorizer;
use HFlow\LaravelWorkflow\Attributes\Condition;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Transition;
use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\ConditionKind;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\StepType;

it('declares the expected native attribute targets and repeatability flags', function (string $class, int $expected): void {
    $reflection = new ReflectionClass($class);
    $attribute = $reflection->getAttributes(Attribute::class)[0] ?? null;

    expect($attribute)->not->toBeNull();

    $flags = $attribute->getArguments()[0] ?? null;

    expect($flags & $expected)->toBe($expected);
})->with([
    'AsWorkflow target class' => [AsWorkflow::class, Attribute::TARGET_CLASS],
    'Step target method/property' => [Step::class, Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY],
    'Action target method repeatable' => [Action::class, Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE],
    'Condition target method/class repeatable' => [Condition::class, Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE],
    'Authorizer target method/property' => [Authorizer::class, Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY],
    'Assignee target method/property repeatable' => [Assignee::class, Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE],
    'Transition target method/property repeatable' => [Transition::class, Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE],
]);

it('provides explicit safe defaults for optional attribute arguments', function (): void {
    $step = new Step(code: 'review', type: StepType::Approval);
    $action = new Action(code: 'approve', type: 'approve');
    $condition = new Condition(code: 'amount_check');

    expect($step->name)->toBe('')
        ->and($step->authorization)->toBe(AuthorizationMode::Public)
        ->and($step->matchMode)->toBe(MatchMode::All)
        ->and($action->availabilityMode)->toBe(ActionAvailabilityMode::General)
        ->and($action->requiresComment)->toBeFalse()
        ->and($condition->kind)->toBe(ConditionKind::Expression);
});
