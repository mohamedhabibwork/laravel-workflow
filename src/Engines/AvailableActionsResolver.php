<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Actions\Action;
use HFlow\LaravelWorkflow\Actions\ActionSet;
use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerInterface;
use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerRegistry;
use HFlow\LaravelWorkflow\Engines\Conditions\ConditionEvaluator;
use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

/**
 * Resolves the set of actions a given user may perform on the current
 * step of a workflow instance.
 *
 *   1) Eligibility: step.authorization_mode → AuthorizerRegistry
 *   2) Per-action availability_mode:
 *        - general    : always pass
 *        - conditional: ConditionEvaluator against instance context
 *        - custom     : host's CustomActionHandler::isAvailable()
 *   3) Ordered by sort_order ASC, then id ASC (determinism)
 */
final class AvailableActionsResolver
{
    public function __construct(
        private readonly AuthorizerRegistry $authorizers,
        private readonly ConditionEvaluator $conditions,
        private readonly ?EligibilityChecker $eligibilityChecker = null,
    ) {}

    public function resolve(WorkflowInstance $instance, mixed $user): ActionSet
    {
        $current = $instance->stepInstances()
            ->where('status', StepInstanceStatus::Active->value)
            ->with(['step.actions', 'step.assignees'])
            ->orderBy('id')
            ->first();

        if (! $current instanceof WorkflowStepInstance) {
            return new ActionSet([]);
        }

        if (! $this->isEligible($user, $instance, $current)) {
            return new ActionSet([]);
        }

        $context = $this->buildContext($instance, $user);

        $actions = $current->step->actions
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->filter(fn (WorkflowStepAction $action) => $this->isActionAvailable($action, $context))
            ->values()
            ->map(fn (WorkflowStepAction $action) => new Action(
                key: (string) $action->code,
                type: $action->type instanceof ActionType ? $action->type : ActionType::Custom,
                label: (string) ($action->label ?? $action->name ?? $action->code),
                availability: $action->availability_mode instanceof ActionAvailabilityMode
                    ? $action->availability_mode
                    : ActionAvailabilityMode::from((string) $action->availability_mode),
                handlerClass: $action->handler,
                requiresComment: (bool) $action->requires_comment,
                nextStepId: $action->target_step_id !== null ? (int) $action->target_step_id : null,
            ))
            ->all();

        return new ActionSet($actions);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function isActionAvailable(WorkflowStepAction $action, array $context): bool
    {
        $mode = $action->availability_mode instanceof ActionAvailabilityMode
            ? $action->availability_mode
            : ActionAvailabilityMode::from((string) $action->availability_mode);

        return match ($mode) {
            ActionAvailabilityMode::General => true,
            ActionAvailabilityMode::Conditional => $this->evaluateGuard($action, $context),
            ActionAvailabilityMode::Custom => $this->invokeCustomAvailability($action, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function evaluateGuard(WorkflowStepAction $action, array $context): bool
    {
        // Action-level guards live on a WorkflowCondition row referenced
        // by guard_condition_id. For now, the structured expression is
        // read from `action.config.expression` (defensive fallback).
        $expression = $action->config['expression'] ?? null;
        if (! is_array($expression)) {
            return true;
        }

        return $this->conditions->evaluate($expression, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function invokeCustomAvailability(WorkflowStepAction $action, array $context): bool
    {
        $handler = $action->handler;
        if (! is_string($handler) || ! class_exists($handler)) {
            return false;
        }
        $impl = app($handler);

        // Host implements any isAvailable() or handle(); if neither, fall
        // back to true so the action appears in the set.
        if (is_object($impl) && method_exists($impl, 'isAvailable')) {
            return (bool) $impl->isAvailable($context);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(WorkflowInstance $instance, mixed $user): array
    {
        return [
            'subject' => $instance->context ?? [],
            'context' => $instance->context ?? [],
            'user' => $user !== null && is_object($user) && method_exists($user, 'toArray')
                ? $user->toArray()
                : ['id' => is_object($user) && method_exists($user, 'getKey') ? $user->getKey() : null],
            'instance' => [
                'id' => $instance->id,
                'uuid' => $instance->uuid,
                'status' => $instance->status->value,
                'workflow_id' => $instance->workflow_id,
                'workflow_version' => $instance->workflow_version,
            ],
        ];
    }

    /**
     * Pick the authorizer matching the step's `authorization_mode`.
     */
    private function authorizerFor(WorkflowStep $step): AuthorizerInterface
    {
        $mode = $step->authorization_mode instanceof AuthorizationMode
            ? $step->authorization_mode
            : AuthorizationMode::from((string) $step->authorization_mode);

        return $this->authorizers->get($mode->value);
    }

    private function isEligible(mixed $user, WorkflowInstance $instance, WorkflowStepInstance $current): bool
    {
        if ($this->eligibilityChecker instanceof EligibilityChecker) {
            return $this->eligibilityChecker->isEligible($user, $instance, $current);
        }

        return $this->authorizerFor($current->step)->authorize($user, $instance, $current, $current->step);
    }
}
