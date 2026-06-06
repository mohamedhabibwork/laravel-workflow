<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

final class ReturnNotAllowedException extends WorkflowException
{
    public static function forStep(string $stepKey): self
    {
        return new self("Step [{$stepKey}] is not returnable.");
    }
}
