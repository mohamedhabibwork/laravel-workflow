<?php

namespace HFlow\LaravelWorkflow\Tests\Support;

use HFlow\LaravelWorkflow\Services\WorkflowEngine;

class CustomWorkflowEngine extends WorkflowEngine
{
    public function customized(): bool
    {
        return true;
    }
}
