<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;

interface AssigneeResolver
{
    /**
     * Resolve explicit eligible user IDs for a workflow step in the given instance.
     *
     * @return array<int>
     */
    public function resolve(WorkflowStep $step, WorkflowInstance $instance): array;
}
