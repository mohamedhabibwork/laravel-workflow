<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowActivity;
use HFlow\LaravelWorkflow\Support\ActivityResult;

interface ActivityHandler
{
    /**
     * @return array<string, mixed>|ActivityResult
     */
    public function handle(WorkflowActivity $activity): array|ActivityResult;
}
