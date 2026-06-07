<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Enums\TransitionType;
use HFlow\LaravelWorkflow\Exceptions\TransitionNotFoundException;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves the next step given the current step and the action being
 * performed (or null for automation).
 *
 *   1) If the action has an explicit `target_step_id`, return it.
 *   2) Else pick the highest-priority matching transition.
 *   3) Else fall back to position-based routing (next position), when
 *      `require_explicit_transitions = false` on the workflow.
 *   4) Else throw TransitionNotFoundException.
 */
final class TransitionResolver
{
    public function resolveNextStep(
        WorkflowStep $currentStep,
        ?WorkflowStepAction $action,
        \Illuminate\Database\Eloquent\Collection $transitions,
    ): WorkflowStep {
        // 1) Explicit target on the action
        if ($action !== null && $action->target_step_id !== null) {
            $target = WorkflowStep::query()->find($action->target_step_id);
            if ($target instanceof WorkflowStep) {
                return $target;
            }
        }

        // 2) Highest-priority matching transition
        $matched = $transitions
            ->where('from_step_id', $currentStep->id)
            ->sortByDesc('priority')
            ->sortBy('id')
            ->first();

        if ($matched instanceof WorkflowTransition) {
            $next = WorkflowStep::query()->find($matched->to_step_id);
            if ($next instanceof WorkflowStep) {
                return $next;
            }
        }

        throw new TransitionNotFoundException(
            "No transition out of step [{$currentStep->code}] for action [" . ($action?->code ?? 'auto') . ']',
        );
    }
}
