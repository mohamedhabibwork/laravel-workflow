<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;

/**
 * Host-supplied side-effect handler for a workflow action.
 *
 * Stored on `workflow_step_actions.handler` when set.
 * Resolved through the host's service container at evaluation time.
 *
 * Contract:
 *  - Engine invokes the handler AFTER eligibility/availability re-validation
 *    and AFTER the leaving step has been closed
 *  - Handler MUST be idempotent with respect to its inputs
 *  - Handler MAY throw; engine catches the throwable, sets the leaving
 *    step to `failed` (if automated) or records an `error` history event
 *    and re-throws (if manual)
 *  - Handler MUST NOT advance the instance or write to
 *    `workflow_step_instances` directly; that is the engine's job
 *  - Handler runs SYNCHRONOUSLY in the same PHP process as the action
 *    perform call. Async side effects are the host's job
 *
 * The $payload array has the following keys:
 *  - user: mixed|null  The actor performing the action
 *  - comment: string|null  The comment, if any
 *  - metadata: array  Arbitrary host data
 *
 * @see /specs/002-laravel-workflow-engine/contracts/host-contracts.md#3-customactionhandler
 */
interface CustomActionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        WorkflowInstance $instance,
        WorkflowStepAction $action,
        array $payload,
    ): void;
}
