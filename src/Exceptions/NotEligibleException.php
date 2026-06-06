<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

final class NotEligibleException extends WorkflowException
{
    public static function forUser(string $userId, string $instanceId): self
    {
        return new self("User [{$userId}] is not eligible to act on instance [{$instanceId}].");
    }
}
