<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

/**
 * Host-supplied side-effect handler for an automated workflow step.
 *
 * Stored on `workflow_steps.handler` when `step.type = automated`.
 * Resolved through the host's service container at evaluation time.
 *
 * Contract:
 *  - Engine invokes the handler IMMEDIATELY on step entry
 *  - Handler MUST return an array; the array is merged into the step
 *    instance's `data` column
 *  - Handler MAY throw; engine catches the throwable, sets the step
 *    instance to `failed` and the instance to `failed`, and records
 *    an `error` history event. Host can then retry() the instance
 *  - Handler MUST NOT call the engine API (no engine->perform(), no
 *    engine->advance())
 *  - Handler runs SYNCHRONOUSLY
 *
 * @return array<string, mixed> Step-local data to merge into $stepInstance->data
 *
 * @see /specs/002-laravel-workflow-engine/contracts/host-contracts.md#4-customstephandler
 */
interface CustomStepHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
    ): array;
}
