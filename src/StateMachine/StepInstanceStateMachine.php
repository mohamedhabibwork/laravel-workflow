<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\StateMachine;

use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;

/**
 * State machine for `workflow_step_instances.status`.
 *
 *   pending    → active
 *   active     → completed, skipped, returned, rejected, failed
 *   completed  → (terminal)
 *   skipped    → (terminal)
 *   returned   → (terminal)
 *   rejected   → (terminal)
 *   failed     → active (retry; spawns a new step instance)
 */
final class StepInstanceStateMachine
{
    private const TRANSITIONS = [
        'pending' => ['active'],
        'active' => ['completed', 'skipped', 'returned', 'rejected', 'failed'],
        'completed' => [],
        'skipped' => [],
        'returned' => [],
        'rejected' => [],
        'failed' => ['active'],
    ];

    public static function canTransition(StepInstanceStatus $from, StepInstanceStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * @return array<int, StepInstanceStatus>
     */
    public static function allowedTransitions(StepInstanceStatus $from): array
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        return array_map(static fn (string $value) => StepInstanceStatus::from($value), $allowed);
    }

    /**
     * @return array<int, StepInstanceStatus>
     */
    public static function states(): array
    {
        return StepInstanceStatus::cases();
    }

    public static function isTerminal(StepInstanceStatus $state): bool
    {
        return self::TRANSITIONS[$state->value] === [];
    }
}
