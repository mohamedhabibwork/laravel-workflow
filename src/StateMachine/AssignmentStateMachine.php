<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\StateMachine;

use HFlow\LaravelWorkflow\Enums\AssignmentStatus;

/**
 * State machine for `workflow_assignments.status`.
 *
 *   pending    → acted, reassigned, expired
 *   acted      → (terminal)
 *   reassigned → (terminal)
 *   expired    → (terminal)
 */
final class AssignmentStateMachine
{
    private const TRANSITIONS = [
        'pending' => ['acted', 'reassigned', 'expired'],
        'acted' => [],
        'reassigned' => [],
        'expired' => [],
    ];

    public static function canTransition(AssignmentStatus $from, AssignmentStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * @return array<int, AssignmentStatus>
     */
    public static function allowedTransitions(AssignmentStatus $from): array
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        return array_map(static fn (string $value) => AssignmentStatus::from($value), $allowed);
    }

    /**
     * @return array<int, AssignmentStatus>
     */
    public static function states(): array
    {
        return AssignmentStatus::cases();
    }

    public static function isTerminal(AssignmentStatus $state): bool
    {
        return self::TRANSITIONS[$state->value] === [];
    }
}
