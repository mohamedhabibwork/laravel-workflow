<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Authorizers;

use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

/**
 * Users — at least one `WorkflowStepAssignee` row must have
 * `assignee_type = 'user'` and `assignee_value` equal to the user's id
 * (or a property the user exposes via `getKey()`).
 */
final class UsersAuthorizer implements AuthorizerInterface
{
    public function mode(): AuthorizationMode
    {
        return AuthorizationMode::Users;
    }

    public function authorize(
        mixed $user,
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
        WorkflowStep $step,
    ): bool {
        if ($user === null) {
            return false;
        }

        $userId = null;
        if (is_object($user) && method_exists($user, 'getKey')) {
            $userId = (int) $user->getKey();
        } elseif (is_int($user) || is_string($user)) {
            $userId = (int) $user;
        }

        if ($userId === null) {
            return false;
        }

        $allowed = $step->assignees()
            ->where('assignee_type', 'user')
            ->pluck('assignee_value')
            ->map(static fn ($v) => (int) $v)
            ->all();

        return in_array($userId, $allowed, true);
    }
}
