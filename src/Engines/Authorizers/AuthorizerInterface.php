<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Authorizers;

use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

/**
 * Dispatcher for the 5 authorization modes.
 *
 * The host decides which implementation is bound to
 * `src/Engines/Authorizers/AuthorizerInterface::class` via the service
 * provider; this interface is what the engine depends on.
 */
interface AuthorizerInterface
{
    /**
     * The {@see AuthorizationMode} this authorizer handles.
     *
     * The {@see AuthorizerRegistry} uses this to dispatch to the correct
     * implementation based on `WorkflowStep.authorization_mode`.
     */
    public function mode(): AuthorizationMode;

    /**
     * Return true if `$user` is allowed to act on the current step instance.
     *
     * MUST be a pure predicate: no mutation, no side effects, no exceptions.
     * Implementations may consult the host application (e.g. `$user->hasRole()`),
     * the workflow definition (e.g. `WorkflowStepAssignee` rows), or both.
     *
     * @param  mixed  $user  The actor (host User model, integer id, or null)
     */
    public function authorize(
        mixed $user,
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
        WorkflowStep $step,
    ): bool;
}
