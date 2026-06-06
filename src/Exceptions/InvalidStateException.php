<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;

final class InvalidStateException extends WorkflowException
{
    public static function forInstance(string $expected, InstanceStatus $actual): self
    {
        return new self("Instance must be in [{$expected}] state, currently [{$actual->value}].");
    }

    public static function forStepInstance(string $expected, StepInstanceStatus $actual): self
    {
        return new self("Step instance must be in [{$expected}] state, currently [{$actual->value}].");
    }
}
