<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

final class TransitionNotFoundException extends WorkflowException
{
    public static function forAction(string $actionCode, string $stepKey): self
    {
        return new self("No transition found for action [{$actionCode}] from step [{$stepKey}].");
    }
}
