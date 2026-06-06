<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

final class ActionNotAvailableException extends WorkflowException
{
    public static function forAction(string $actionCode, string $instanceId): self
    {
        return new self("Action [{$actionCode}] is not in the available actions for instance [{$instanceId}].");
    }
}
