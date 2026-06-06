<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

use HFlow\LaravelWorkflow\Enums\InstanceStatus;

final class WorkflowTerminalException extends WorkflowException
{
    public static function forInstance(InstanceStatus $status): self
    {
        return new self("Workflow instance is in terminal state [{$status->value}] and cannot be modified.");
    }
}
