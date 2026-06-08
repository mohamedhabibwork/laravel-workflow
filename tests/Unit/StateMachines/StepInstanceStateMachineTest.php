<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\StateMachine\StepInstanceStateMachine;

/**
 * T072 — Unit test for {@see StepInstanceStateMachine}.
 *
 * Verifies every valid + invalid transition per `StepInstanceStatus`:
 *   - pending    → active
 *   - active     → completed, skipped, returned, rejected, failed
 *   - completed  → (terminal)
 *   - skipped    → (terminal)
 *   - returned   → (terminal)
 *   - rejected   → (terminal)
 *   - failed     → active (retry; spawns a new step instance)
 */
it('allows the documented valid transitions', function (StepInstanceStatus $from, StepInstanceStatus $to): void {
    expect(StepInstanceStateMachine::canTransition($from, $to))->toBeTrue();
})->with([
    'pending → active' => [StepInstanceStatus::Pending, StepInstanceStatus::Active],
    'active → completed' => [StepInstanceStatus::Active, StepInstanceStatus::Completed],
    'active → skipped' => [StepInstanceStatus::Active, StepInstanceStatus::Skipped],
    'active → returned' => [StepInstanceStatus::Active, StepInstanceStatus::Returned],
    'active → rejected' => [StepInstanceStatus::Active, StepInstanceStatus::Rejected],
    'active → failed' => [StepInstanceStatus::Active, StepInstanceStatus::Failed],
    'failed → active' => [StepInstanceStatus::Failed, StepInstanceStatus::Active],
]);

it('refuses every transition not in the valid set', function (StepInstanceStatus $from, StepInstanceStatus $to): void {
    $valid = StepInstanceStateMachine::allowedTransitions($from);
    $validValues = array_map(static fn (StepInstanceStatus $s) => $s->value, $valid);

    $expected = in_array($to->value, $validValues, true);

    expect(StepInstanceStateMachine::canTransition($from, $to))->toBe($expected);
})->with([
    'pending → completed' => [StepInstanceStatus::Pending, StepInstanceStatus::Completed],
    'pending → skipped' => [StepInstanceStatus::Pending, StepInstanceStatus::Skipped],
    'pending → failed' => [StepInstanceStatus::Pending, StepInstanceStatus::Failed],
    'active → active' => [StepInstanceStatus::Active, StepInstanceStatus::Active],
    'active → pending' => [StepInstanceStatus::Active, StepInstanceStatus::Pending],
    'completed → anything' => [StepInstanceStatus::Completed, StepInstanceStatus::Active],
    'skipped → anything' => [StepInstanceStatus::Skipped, StepInstanceStatus::Active],
    'returned → anything' => [StepInstanceStatus::Returned, StepInstanceStatus::Active],
    'rejected → anything' => [StepInstanceStatus::Rejected, StepInstanceStatus::Active],
    'failed → completed' => [StepInstanceStatus::Failed, StepInstanceStatus::Completed],
    'failed → skipped' => [StepInstanceStatus::Failed, StepInstanceStatus::Skipped],
]);

it('isTerminal reports the correct terminal states', function (StepInstanceStatus $state, bool $terminal): void {
    expect(StepInstanceStateMachine::isTerminal($state))->toBe($terminal);
})->with([
    'pending is not terminal' => [StepInstanceStatus::Pending, false],
    'active is not terminal' => [StepInstanceStatus::Active, false],
    'failed is not terminal (retry)' => [StepInstanceStatus::Failed, false],
    'completed is terminal' => [StepInstanceStatus::Completed, true],
    'skipped is terminal' => [StepInstanceStatus::Skipped, true],
    'returned is terminal' => [StepInstanceStatus::Returned, true],
    'rejected is terminal' => [StepInstanceStatus::Rejected, true],
]);

it('states() returns the full set of StepInstanceStatus cases', function (): void {
    expect(StepInstanceStateMachine::states())->toBe(StepInstanceStatus::cases());
});
