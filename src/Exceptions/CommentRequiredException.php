<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

final class CommentRequiredException extends WorkflowException
{
    public static function forAction(string $actionCode): self
    {
        return new self("Action [{$actionCode}] requires a comment.");
    }
}
