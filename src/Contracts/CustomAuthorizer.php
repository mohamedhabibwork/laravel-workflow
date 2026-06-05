<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

/**
 * Host-supplied authorization check for a workflow step.
 *
 * Stored on `workflow_steps.custom_authorizer` when `authorization_mode = custom`.
 * Resolved through the host's service container at evaluation time.
 *
 * Contract:
 *  - MUST return true if the user is eligible, false otherwise
 *  - MUST NOT mutate the instance, the step instance, or any related row
 *  - MUST be safe to call repeatedly on the same inputs
 *  - MUST NOT throw; return false on any internal error
 *
 * @see /specs/002-laravel-workflow-engine/contracts/host-contracts.md#1-customauthorizer
 */
interface CustomAuthorizer
{
    public function authorize(
        mixed $user,
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
    ): bool;
}
