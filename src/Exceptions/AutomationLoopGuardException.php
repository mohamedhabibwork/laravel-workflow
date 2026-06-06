<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

final class AutomationLoopGuardException extends WorkflowException
{
    public static function exceeded(int $maxDepth): self
    {
        return new self("Automation chain exceeded max chain depth of [{$maxDepth}].");
    }
}
