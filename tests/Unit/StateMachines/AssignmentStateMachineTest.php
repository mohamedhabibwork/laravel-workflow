<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Enums\AssignmentStatus;
use HFlow\LaravelWorkflow\StateMachine\AssignmentStateMachine;

/**
 * T072 — Unit test for {@see AssignmentStateMachine}.
 *
 * Verifies every valid + invalid transition per `AssignmentStatus`:
 *   - pending    → acted, reassigned, expired
 *   - acted      → (terminal)
 *   - reassigned → (terminal)
 *   - expired    → (terminal)
 */
it('allows the documented valid transitions', function (AssignmentStatus $from, AssignmentStatus $to): void {
    expect(AssignmentStateMachine::canTransition($from, $to))->toBeTrue();
})->with([
    'pending → acted' => [AssignmentStatus::Pending, AssignmentStatus::Acted],
    'pending → reassigned' => [AssignmentStatus::Pending, AssignmentStatus::Reassigned],
    'pending → expired' => [AssignmentStatus::Pending, AssignmentStatus::Expired],
]);

it('refuses every transition not in the valid set', function (AssignmentStatus $from, AssignmentStatus $to): void {
    $valid = AssignmentStateMachine::allowedTransitions($from);
    $validValues = array_map(static fn (AssignmentStatus $s) => $s->value, $valid);

    $expected = in_array($to->value, $validValues, true);

    expect(AssignmentStateMachine::canTransition($from, $to))->toBe($expected);
})->with([
    'pending → pending' => [AssignmentStatus::Pending, AssignmentStatus::Pending],
    'acted → anything' => [AssignmentStatus::Acted, AssignmentStatus::Pending],
    'acted → expired' => [AssignmentStatus::Acted, AssignmentStatus::Expired],
    'reassigned → anything' => [AssignmentStatus::Reassigned, AssignmentStatus::Pending],
    'expired → anything' => [AssignmentStatus::Expired, AssignmentStatus::Acted],
]);

it('isTerminal reports the correct terminal states', function (AssignmentStatus $state, bool $terminal): void {
    expect(AssignmentStateMachine::isTerminal($state))->toBe($terminal);
})->with([
    'pending is not terminal' => [AssignmentStatus::Pending, false],
    'acted is terminal' => [AssignmentStatus::Acted, true],
    'reassigned is terminal' => [AssignmentStatus::Reassigned, true],
    'expired is terminal' => [AssignmentStatus::Expired, true],
]);

it('states() returns the full set of AssignmentStatus cases', function (): void {
    expect(AssignmentStateMachine::states())->toBe(AssignmentStatus::cases());
});
