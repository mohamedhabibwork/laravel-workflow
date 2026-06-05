<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Contracts;

/**
 * Host-supplied boolean predicate evaluated against the engine context.
 *
 * Stored on `workflow_conditions.evaluator` when `kind = custom`.
 * Resolved through the host's service container at evaluation time.
 *
 * Contract:
 *  - MUST return true to pass the guard, false to fail it
 *  - MUST NOT mutate the instance, the subject, or any related row
 *  - MUST be pure with respect to its inputs
 *  - MUST NOT throw; return false on any internal error
 *
 * The $context array has the following keys (all nullable):
 *  - subject: \Illuminate\Database\Eloquent\Model  The host model bound to the instance
 *  - user: mixed  The current user (when evaluated in a user-driven context)
 *  - instance: \HFlow\LaravelWorkflow\Models\WorkflowInstance
 *  - step_instance: \HFlow\LaravelWorkflow\Models\WorkflowStepInstance|null
 *
 * @see /specs/002-laravel-workflow-engine/contracts/host-contracts.md#2-customconditionevaluator
 */
interface CustomConditionEvaluator
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function evaluate(array $context): bool;
}
