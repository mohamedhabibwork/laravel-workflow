<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\StateMachine;

use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use Illuminate\Support\Collection;

/**
 * State machine for `workflows.status`.
 *
 *   draft     → active, archived
 *   active    → archived
 *   archived  → active
 */
final class WorkflowStateMachine
{
    private const TRANSITIONS = [
        'draft' => ['active', 'archived'],
        'active' => ['archived'],
        'archived' => ['active'],
    ];

    public static function canTransition(WorkflowStatus $from, WorkflowStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * @return array<int, WorkflowStatus>
     */
    public static function allowedTransitions(WorkflowStatus $from): array
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        return array_map(static fn (string $value) => WorkflowStatus::from($value), $allowed);
    }

    /**
     * @return array<int, WorkflowStatus>
     */
    public static function states(): array
    {
        return WorkflowStatus::cases();
    }

    public static function isTerminal(WorkflowStatus $state): bool
    {
        return self::TRANSITIONS[$state->value] === [];
    }

    /**
     * Activation guard: exactly one start step, at least one end step.
     *
     * @param  Collection<int, mixed>  $steps
     */
    public static function canActivate(Collection $steps): bool
    {
        $startCount = $steps->where('type', StepType::Start->value)->count();
        $endCount = $steps->where('type', StepType::End->value)->count();

        return $startCount === 1 && $endCount >= 1;
    }
}
