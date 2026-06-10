<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowTimer;

interface WorkflowTimerHandler
{
    public function handle(WorkflowInstance $instance, WorkflowTimer $timer): void;
}
