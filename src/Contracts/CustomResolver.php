<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;

/**
 * Host-supplied resolver of "who can act on this step" for a workflow instance.
 *
 * Stored on `workflow_step_assignees.custom_resolver` when `assignee_type = custom`.
 * Resolved through the host's service container at evaluation time.
 *
 * Contract:
 *  - Resolver MUST return an iterable of host user instances (or empty)
 *  - Resolver MUST NOT mutate the instance, the step, or any related row
 *  - Resolver MUST be safe to call multiple times (engine may call it on
 *    every available-actions query for cache freshness)
 *  - Resolver MUST NOT throw; return an empty iterable on any internal error
 *
 * @return iterable<mixed> The set of host user instances eligible to act
 *
 * @see /specs/002-laravel-workflow-engine/contracts/host-contracts.md#5-customresolver
 */
interface CustomResolver
{
    /**
     * @return iterable<mixed>
     */
    public function resolve(WorkflowInstance $instance, WorkflowStep $step): iterable;
}
