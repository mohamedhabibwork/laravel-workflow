<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

interface ActionHandler
{
    /**
     * Handle side effects after a workflow action has been accepted.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(WorkflowStepInstance $stepInstance, string $actionCode, array $payload): void;
}
