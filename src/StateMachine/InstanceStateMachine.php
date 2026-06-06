<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\StateMachine;

use HFlow\LaravelWorkflow\Enums\InstanceStatus;

/**
 * State machine for `workflow_instances.status`.
 *
 *   pending      → in_progress, cancelled
 *   in_progress  → on_hold, completed, rejected, failed, cancelled
 *   on_hold      → in_progress, cancelled
 *   completed    → (terminal)
 *   cancelled    → (terminal)
 *   rejected     → (terminal)
 *   failed       → in_progress (retry)
 */
final class InstanceStateMachine
{
    private const TRANSITIONS = [
        'pending'     => ['in_progress', 'cancelled'],
        'in_progress' => ['on_hold', 'completed', 'rejected', 'failed', 'cancelled'],
        'on_hold'     => ['in_progress', 'cancelled'],
        'completed'   => [],
        'cancelled'   => [],
        'rejected'    => [],
        'failed'      => ['in_progress'],
    ];

    public static function canTransition(InstanceStatus $from, InstanceStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * @return array<int, InstanceStatus>
     */
    public static function allowedTransitions(InstanceStatus $from): array
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        return array_map(static fn (string $value) => InstanceStatus::from($value), $allowed);
    }

    /**
     * @return array<int, InstanceStatus>
     */
    public static function states(): array
    {
        return InstanceStatus::cases();
    }

    public static function isTerminal(InstanceStatus $state): bool
    {
        return self::TRANSITIONS[$state->value] === [];
    }
}
