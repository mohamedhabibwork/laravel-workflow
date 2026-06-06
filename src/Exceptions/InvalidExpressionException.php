<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

final class InvalidExpressionException extends WorkflowException
{
    public static function atPath(string $path, string $reason): self
    {
        return new self("Invalid expression at [{$path}]: {$reason}");
    }
}
