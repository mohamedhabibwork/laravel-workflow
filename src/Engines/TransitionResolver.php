<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

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
    public function __construct(
        private readonly ?ActionTargetRouter $actionTargetRouter = null,
        private readonly ?SequentialFallbackRouter $sequentialFallbackRouter = null,
    ) {}

    /**
     * @param  Collection<int, WorkflowTransition>  $transitions
     */
    public function resolveNextStep(
        WorkflowStep $currentStep,
        ?WorkflowStepAction $action,
        Collection $transitions,
    ): WorkflowStep {
        // 1) Explicit target on the action
        $target = $this->actionTargetRouter()->route($action);
        if ($target instanceof WorkflowStep) {
            return $target;
        }

        // 2) Highest-priority matching transition
        $matched = $transitions
            ->where('from_step_id', $currentStep->id)
            ->filter(function (WorkflowTransition $transition) use ($action): bool {
                if ($transition->action_id === null) {
                    return true;
                }

                return $action instanceof WorkflowStepAction
                    && (int) $transition->action_id === (int) $action->getKey();
            })
            ->sort(function (WorkflowTransition $left, WorkflowTransition $right) use ($action): int {
                $leftExact = $action instanceof WorkflowStepAction && (int) $left->action_id === (int) $action->getKey() ? 0 : 1;
                $rightExact = $action instanceof WorkflowStepAction && (int) $right->action_id === (int) $action->getKey() ? 0 : 1;

                return [$leftExact, -((int) $left->priority), (int) $left->id]
                    <=> [$rightExact, -((int) $right->priority), (int) $right->id];
            })
            ->first();

        if ($matched instanceof WorkflowTransition) {
            $next = WorkflowStep::query()->find($matched->to_step_id);
            if ($next instanceof WorkflowStep) {
                return $next;
            }
        }

        $fallback = $this->sequentialFallbackRouter()->route($currentStep);
        if ($fallback instanceof WorkflowStep) {
            return $fallback;
        }

        throw new TransitionNotFoundException(
            "No transition out of step [{$currentStep->code}] for action [".($action ? $action->code : 'auto').']',
        );
    }

    private function actionTargetRouter(): ActionTargetRouter
    {
        return $this->actionTargetRouter ?? new ActionTargetRouter;
    }

    private function sequentialFallbackRouter(): SequentialFallbackRouter
    {
        return $this->sequentialFallbackRouter ?? new SequentialFallbackRouter;
    }
}
