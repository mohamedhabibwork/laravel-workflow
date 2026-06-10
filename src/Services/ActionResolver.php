<?php

namespace HFlow\LaravelWorkflow\Services;

use HFlow\LaravelWorkflow\Contracts\CustomAuthorizer;
use HFlow\LaravelWorkflow\Enums\AssigneeType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\AvailabilityMode;
use HFlow\LaravelWorkflow\Enums\StepStatus;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

class ActionResolver
{
    /**
     * Create the resolver with the condition evaluator and service container.
     */
    public function __construct(
        protected ?ConditionEvaluator $conditionEvaluator = null,
        protected ?Container $container = null,
    ) {
        $this->container ??= \Illuminate\Container\Container::getInstance();
        $this->conditionEvaluator ??= new ConditionEvaluator($this->container);
    }

    /**
     * Resolve actions available to the user across active step instances.
     *
     * @return Collection<int, WorkflowStepAction>
     */
    public function resolve(WorkflowInstance $instance, ?User $user): Collection
    {
        // BR-X-06 — Resolve the active step instance(s) of the instance.
        $activeStepInstances = $instance->stepInstances()
            ->where('status', StepStatus::Active)
            ->with(['step', 'step.actions'])
            ->get();

        $availableActions = collect();

        foreach ($activeStepInstances as $stepInstance) {
            // BR-X-07 — Eligibility: evaluate the step’s authorization_mode against the user.
            if ($user === null && $stepInstance->step->authorization_mode !== AuthorizationMode::Public) {
                continue;
            }

            if ($user !== null && ! $this->isEligible($stepInstance, $user)) {
                continue;
            }

            // BR-X-08 — Action gathering: collect the step’s actions.
            $actions = $stepInstance->step->actions;

            foreach ($actions as $action) {
                // BR-X-09 — For each action, evaluate availability_mode.
                if ($this->isAvailable($action, $instance, $stepInstance, $user)) {
                    $availableActions->push($action);
                }
            }
        }

        return $availableActions;
    }

    /**
     * Determine whether the user satisfies the active step authorization mode.
     */
    protected function isEligible(WorkflowStepInstance $stepInstance, User $user): bool
    {
        $step = $stepInstance->step;

        return match ($step->authorization_mode) {
            AuthorizationMode::Public => true,
            AuthorizationMode::Users => $step->assignees()
                ->where('assignee_type', AssigneeType::User)
                ->where('assignee_value', (string) $user->getAuthIdentifier())
                ->exists(),
            AuthorizationMode::Roles => $this->checkRoles($step, $user),
            AuthorizationMode::Permissions => $this->checkPermissions($step, $user),
            AuthorizationMode::Custom => $this->checkCustomAuthorizer($stepInstance, $user),
        };
    }

    /**
     * Check whether the user exposes and satisfies the required role assignments.
     */
    protected function checkRoles(WorkflowStep $step, User $user): bool
    {
        $requiredRoles = $step->assignees()
            ->where('assignee_type', AssigneeType::Role)
            ->pluck('assignee_value');

        if ($requiredRoles->isEmpty()) {
            return false;
        }

        // Host provides role check. Usually $user->hasRole() or similar.
        // For MVP, we assume the user model has a roles collection or similar.
        // We'll use a gate or a common method name.
        if (method_exists($user, 'hasAnyRole')) {
            return $user->hasAnyRole($requiredRoles->toArray());
        }

        return false;
    }

    /**
     * Check whether the user exposes and satisfies the required permission assignments.
     */
    protected function checkPermissions(WorkflowStep $step, User $user): bool
    {
        $requiredPermissions = $step->assignees()
            ->where('assignee_type', AssigneeType::Permission)
            ->pluck('assignee_value');

        if ($requiredPermissions->isEmpty()) {
            return false;
        }

        if (method_exists($user, 'hasAnyPermission')) {
            return $user->hasAnyPermission($requiredPermissions->toArray());
        }

        return false;
    }

    /**
     * Resolve and execute the configured custom authorizer for the step.
     */
    protected function checkCustomAuthorizer(WorkflowStepInstance $stepInstance, User $user): bool
    {
        $className = $stepInstance->step->custom_authorizer;

        if (empty($className) || ! class_exists($className)) {
            return false;
        }

        $authorizer = $this->container->make($className);

        if (! $authorizer instanceof CustomAuthorizer) {
            return false;
        }

        return $authorizer->authorize($user, $stepInstance->workflowInstance, $stepInstance);
    }

    /**
     * Determine whether an action passes its availability mode and guards.
     */
    protected function isAvailable(WorkflowStepAction $action, WorkflowInstance $instance, WorkflowStepInstance $stepInstance, ?User $user): bool
    {
        return match ($action->availability_mode) {
            AvailabilityMode::General => true,
            AvailabilityMode::Conditional => $this->conditionEvaluator->evaluate($action->guardCondition, $instance, $instance->subject, $instance->context, $user),
            AvailabilityMode::Custom => $this->checkCustomAvailability($action, $instance, $stepInstance, $user),
        };
    }

    /**
     * Resolve and execute the configured custom availability guard.
     */
    protected function checkCustomAvailability(WorkflowStepAction $action, WorkflowInstance $instance, WorkflowStepInstance $stepInstance, ?User $user): bool
    {
        $className = $action->guard_class;

        if (empty($className) || ! class_exists($className)) {
            return false;
        }

        // Custom availability can use a similar interface to ConditionEvaluator or CustomAuthorizer.
        // For now, we'll assume it's a class with an evaluate method.
        $evaluator = $this->container->make($className);

        return $evaluator->evaluate($instance, $instance->subject, $instance->context, $user);
    }
}
