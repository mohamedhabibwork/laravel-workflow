<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\StateMachine\InstanceStateMachine;

/**
 * T072 — Unit test for {@see InstanceStateMachine}.
 *
 * Verifies every valid + invalid transition per `InstanceStatus`:
 *   - pending      → in_progress, cancelled
 *   - in_progress  → on_hold, completed, rejected, failed, cancelled
 *   - on_hold      → in_progress, cancelled
 *   - completed    → (terminal)
 *   - cancelled    → (terminal)
 *   - rejected     → (terminal)
 *   - failed       → in_progress (retry)
 */
it('allows the documented valid transitions', function (InstanceStatus $from, InstanceStatus $to): void {
    expect(InstanceStateMachine::canTransition($from, $to))->toBeTrue();
})->with([
    'pending → in_progress' => [InstanceStatus::Pending, InstanceStatus::InProgress],
    'pending → cancelled' => [InstanceStatus::Pending, InstanceStatus::Cancelled],
    'in_progress → on_hold' => [InstanceStatus::InProgress, InstanceStatus::OnHold],
    'in_progress → completed' => [InstanceStatus::InProgress, InstanceStatus::Completed],
    'in_progress → rejected' => [InstanceStatus::InProgress, InstanceStatus::Rejected],
    'in_progress → failed' => [InstanceStatus::InProgress, InstanceStatus::Failed],
    'in_progress → cancelled' => [InstanceStatus::InProgress, InstanceStatus::Cancelled],
    'on_hold → in_progress' => [InstanceStatus::OnHold, InstanceStatus::InProgress],
    'on_hold → cancelled' => [InstanceStatus::OnHold, InstanceStatus::Cancelled],
    'failed → in_progress' => [InstanceStatus::Failed, InstanceStatus::InProgress],
]);

it('refuses every transition not in the valid set', function (InstanceStatus $from, InstanceStatus $to): void {
    $valid = InstanceStateMachine::allowedTransitions($from);
    $validValues = array_map(static fn (InstanceStatus $s) => $s->value, $valid);

    $expected = in_array($to->value, $validValues, true);

    expect(InstanceStateMachine::canTransition($from, $to))->toBe($expected);
})->with([
    'pending → on_hold' => [InstanceStatus::Pending, InstanceStatus::OnHold],
    'pending → completed' => [InstanceStatus::Pending, InstanceStatus::Completed],
    'pending → rejected' => [InstanceStatus::Pending, InstanceStatus::Rejected],
    'pending → failed' => [InstanceStatus::Pending, InstanceStatus::Failed],
    'in_progress → in_progress' => [InstanceStatus::InProgress, InstanceStatus::InProgress],
    'in_progress → pending' => [InstanceStatus::InProgress, InstanceStatus::Pending],
    'on_hold → on_hold' => [InstanceStatus::OnHold, InstanceStatus::OnHold],
    'on_hold → completed' => [InstanceStatus::OnHold, InstanceStatus::Completed],
    'on_hold → rejected' => [InstanceStatus::OnHold, InstanceStatus::Rejected],
    'on_hold → failed' => [InstanceStatus::OnHold, InstanceStatus::Failed],
    'completed → anything' => [InstanceStatus::Completed, InstanceStatus::InProgress],
    'cancelled → anything' => [InstanceStatus::Cancelled, InstanceStatus::InProgress],
    'rejected → anything' => [InstanceStatus::Rejected, InstanceStatus::InProgress],
    'failed → cancelled' => [InstanceStatus::Failed, InstanceStatus::Cancelled],
    'failed → completed' => [InstanceStatus::Failed, InstanceStatus::Completed],
]);

it('isTerminal reports the correct terminal states', function (InstanceStatus $state, bool $terminal): void {
    expect(InstanceStateMachine::isTerminal($state))->toBe($terminal);
})->with([
    'pending is not terminal' => [InstanceStatus::Pending, false],
    'in_progress is not terminal' => [InstanceStatus::InProgress, false],
    'on_hold is not terminal' => [InstanceStatus::OnHold, false],
    'failed is not terminal (retry)' => [InstanceStatus::Failed, false],
    'completed is terminal' => [InstanceStatus::Completed, true],
    'cancelled is terminal' => [InstanceStatus::Cancelled, true],
    'rejected is terminal' => [InstanceStatus::Rejected, true],
]);

it('states() returns the full set of InstanceStatus cases', function (): void {
    expect(InstanceStateMachine::states())->toBe(InstanceStatus::cases());
});
