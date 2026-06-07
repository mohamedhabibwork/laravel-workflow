<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Authorizers;

use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

/**
 * Permissions — the user must hold a Laravel Gate permission.
 */
final class PermissionsAuthorizer implements AuthorizerInterface
{
    public function mode(): AuthorizationMode
    {
        return AuthorizationMode::Permissions;
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

        $required = $step->assignees()
            ->where('assignee_type', 'permission')
            ->pluck('assignee_value')
            ->all();

        if ($required === []) {
            return false;
        }

        if (method_exists($user, 'can')) {
            foreach ($required as $permission) {
                if ($user->can($permission)) {
                    return true;
                }
            }
        }

        return false;
    }
}
