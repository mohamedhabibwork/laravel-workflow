<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

final class InvalidWorkflowException extends WorkflowException
{
    public static function notActive(string $code): self
    {
        return new self("Workflow [{$code}] is not active and cannot start instances.");
    }

    public static function invalidGraph(string $reason): self
    {
        return new self("Invalid workflow definition: {$reason}");
    }
}
