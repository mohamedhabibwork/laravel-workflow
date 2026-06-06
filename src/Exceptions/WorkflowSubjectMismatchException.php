<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

final class WorkflowSubjectMismatchException extends WorkflowException
{
    public static function forWorkflow(string $workflowKey, string $expected, string $actual): self
    {
        return new self("Workflow [{$workflowKey}] requires subject of type [{$expected}], got [{$actual}].");
    }
}
