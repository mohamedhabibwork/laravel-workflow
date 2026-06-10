<?php

namespace HFlow\LaravelWorkflow\Tests\Support;

use HFlow\LaravelWorkflow\LaravelWorkflow;

class CustomLaravelWorkflow extends LaravelWorkflow
{
    public function customized(): bool
    {
        return true;
    }
}
