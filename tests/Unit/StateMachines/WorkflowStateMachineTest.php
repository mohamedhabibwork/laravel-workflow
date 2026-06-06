<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\StateMachine\WorkflowStateMachine;

/**
 * T025 — Unit test for {@see WorkflowStateMachine}.
 *
 * Verifies every valid + invalid transition per `WorkflowStatus`:
 *   - draft    → active, archived
 *   - active   → archived
 *   - archived → active
 *   - refuse every other pair
 *
 * Also covers `canActivate()` and `states()`.
 */
it('allows the documented valid transitions', function (WorkflowStatus $from, WorkflowStatus $to): void {
    expect(WorkflowStateMachine::canTransition($from, $to))->toBeTrue();
})->with([
    'draft → active' => [WorkflowStatus::Draft, WorkflowStatus::Active],
    'draft → archived' => [WorkflowStatus::Draft, WorkflowStatus::Archived],
    'active → archived' => [WorkflowStatus::Active, WorkflowStatus::Archived],
    'archived → active' => [WorkflowStatus::Archived, WorkflowStatus::Active],
]);

it('refuses every transition not in the valid set', function (WorkflowStatus $from, WorkflowStatus $to): void {
    $valid = WorkflowStateMachine::allowedTransitions($from);
    $validValues = array_map(static fn (WorkflowStatus $s) => $s->value, $valid);

    if (in_array($to->value, $validValues, true)) {
        expect(WorkflowStateMachine::canTransition($from, $to))->toBeTrue(
            "Transition {$from->value} → {$to->value} must be allowed",
        );
    } else {
        expect(WorkflowStateMachine::canTransition($from, $to))->toBeFalse(
            "Transition {$from->value} → {$to->value} must be refused",
        );
    }
})->with([
    'draft → draft' => [WorkflowStatus::Draft, WorkflowStatus::Draft],
    'draft → active' => [WorkflowStatus::Draft, WorkflowStatus::Active],
    'draft → archived' => [WorkflowStatus::Draft, WorkflowStatus::Archived],
    'active → draft' => [WorkflowStatus::Active, WorkflowStatus::Draft],
    'active → active' => [WorkflowStatus::Active, WorkflowStatus::Active],
    'active → archived' => [WorkflowStatus::Active, WorkflowStatus::Archived],
    'archived → draft' => [WorkflowStatus::Archived, WorkflowStatus::Draft],
    'archived → active' => [WorkflowStatus::Archived, WorkflowStatus::Active],
    'archived → archived' => [WorkflowStatus::Archived, WorkflowStatus::Archived],
]);

it('refuses invalid pairs explicitly', function (WorkflowStatus $from, WorkflowStatus $to): void {
    expect(WorkflowStateMachine::canTransition($from, $to))->toBeFalse();
})->with([
    'active → draft' => [WorkflowStatus::Active, WorkflowStatus::Draft],
    'archived → draft' => [WorkflowStatus::Archived, WorkflowStatus::Draft],
    'draft → draft' => [WorkflowStatus::Draft, WorkflowStatus::Draft],
    'active → active' => [WorkflowStatus::Active, WorkflowStatus::Active],
    'archived → archived' => [WorkflowStatus::Archived, WorkflowStatus::Archived],
]);

it('reports allowed transitions for each source state', function (): void {
    expect(array_map(static fn (WorkflowStatus $s) => $s->value, WorkflowStateMachine::allowedTransitions(WorkflowStatus::Draft)))
        ->toBe([WorkflowStatus::Active->value, WorkflowStatus::Archived->value]);
    expect(array_map(static fn (WorkflowStatus $s) => $s->value, WorkflowStateMachine::allowedTransitions(WorkflowStatus::Active)))
        ->toBe([WorkflowStatus::Archived->value]);
    expect(array_map(static fn (WorkflowStatus $s) => $s->value, WorkflowStateMachine::allowedTransitions(WorkflowStatus::Archived)))
        ->toBe([WorkflowStatus::Active->value]);
});

it('isTerminal() matches the state machine definitions', function (): void {
    expect(WorkflowStateMachine::isTerminal(WorkflowStatus::Draft))->toBeFalse()
        ->and(WorkflowStateMachine::isTerminal(WorkflowStatus::Active))->toBeFalse()
        ->and(WorkflowStateMachine::isTerminal(WorkflowStatus::Archived))->toBeFalse();
});

it('states() returns all WorkflowStatus cases', function (): void {
    expect(WorkflowStateMachine::states())->toBe(WorkflowStatus::cases());
});

it('canActivate() returns true for exactly 1 start + at least 1 end', function (): void {
    $steps = collect([
        new WorkflowStep(['type' => StepType::Start->value]),
        new WorkflowStep(['type' => StepType::Task->value]),
        new WorkflowStep(['type' => StepType::End->value]),
    ]);

    expect(WorkflowStateMachine::canActivate($steps))->toBeTrue();
});

it('canActivate() returns false for 0 starts', function (): void {
    $steps = collect([
        new WorkflowStep(['type' => StepType::Task->value]),
        new WorkflowStep(['type' => StepType::End->value]),
    ]);

    expect(WorkflowStateMachine::canActivate($steps))->toBeFalse();
});

it('canActivate() returns false for 2 starts', function (): void {
    $steps = collect([
        new WorkflowStep(['type' => StepType::Start->value]),
        new WorkflowStep(['type' => StepType::Start->value]),
        new WorkflowStep(['type' => StepType::End->value]),
    ]);

    expect(WorkflowStateMachine::canActivate($steps))->toBeFalse();
});

it('canActivate() returns false for 0 ends', function (): void {
    $steps = collect([
        new WorkflowStep(['type' => StepType::Start->value]),
        new WorkflowStep(['type' => StepType::Task->value]),
    ]);

    expect(WorkflowStateMachine::canActivate($steps))->toBeFalse();
});
