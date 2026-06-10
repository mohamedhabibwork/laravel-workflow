<?php

namespace HFlow\LaravelWorkflow\Tests\Support;

use HFlow\LaravelWorkflow\Builders\WorkflowBuilder;

class CustomWorkflowBuilder extends WorkflowBuilder
{
    public function customized(): bool
    {
        return true;
    }
}
