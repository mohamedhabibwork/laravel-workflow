<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Authorizers;

use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

/**
 * Roles — the user must hold at least one of the roles listed in
 * `WorkflowStepAssignee` rows where `assignee_type = 'role'`.
 *
 * Tries (in order): `$user->hasRole($key)`, then a host-configured
 * `user_roles` array on the model. As a last resort, returns false
 * (rather than throwing) — authorizers are pure predicates.
 */
final class RolesAuthorizer implements AuthorizerInterface
{
    public function mode(): AuthorizationMode
    {
        return AuthorizationMode::Roles;
    }

    public function authorize(
        mixed $user,
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
        WorkflowStep $step,
    ): bool {
        if (! is_object($user)) {
            return false;
        }

        $requiredRoles = $step->assignees()
            ->where('assignee_type', 'role')
            ->pluck('assignee_value')
            ->all();

        if ($requiredRoles === []) {
            return false;
        }

        // Try host-side hasRole() method
        if (method_exists($user, 'hasRole')) {
            foreach ($requiredRoles as $role) {
                if ($user->hasRole($role)) {
                    return true;
                }
            }

            return false;
        }

        // Fall back to user_roles property if exposed
        if (isset($user->user_roles) && is_array($user->user_roles)) {
            return (bool) array_intersect($requiredRoles, $user->user_roles);
        }

        return false;
    }
}
