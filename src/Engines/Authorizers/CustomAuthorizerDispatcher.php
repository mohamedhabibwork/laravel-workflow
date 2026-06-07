<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Authorizers;

use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

/**
 * Custom — delegates to a host-supplied FQCN resolved through Laravel's
 * container. The host class must implement `AuthorizerInterface` and is
 * fetched from `step.custom_authorizer`.
 *
 * Falls back to a no-op (returns false) when the FQCN is missing or
 * cannot be resolved.
 */
final class CustomAuthorizerDispatcher implements AuthorizerInterface
{
    public function mode(): AuthorizationMode
    {
        return AuthorizationMode::Custom;
    }

    public function authorize(
        mixed $user,
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
        WorkflowStep $step,
    ): bool {
        $fqcn = $step->custom_authorizer;

        if (! is_string($fqcn) || $fqcn === '') {
            return false;
        }

        if (! class_exists($fqcn)) {
            return false;
        }

        $impl = app($fqcn);
        if (! $impl instanceof AuthorizerInterface) {
            return false;
        }

        return $impl->authorize($user, $instance, $stepInstance, $step);
    }
}
