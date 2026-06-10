<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

interface StepHandler
{
    /**
     * Execute automated logic for the step and return data stored on the step instance.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function handle(WorkflowStepInstance $stepInstance, array $context): array;
}
