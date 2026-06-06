<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow;

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;

/**
 * @deprecated Use the {@see Facades\LaravelWorkflow} facade
 *             or type-hint {@see WorkflowEngine} in your constructor instead.
 *             This class is a backward-compatible alias and will be removed in v2.0.
 */
class LaravelWorkflow
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function engine(): WorkflowEngine
    {
        return $this->engine;
    }
}
