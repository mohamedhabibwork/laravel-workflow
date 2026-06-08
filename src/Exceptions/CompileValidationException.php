<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

final class CompileValidationException extends WorkflowException
{
    /**
     * @param  list<array{rule: string, message: string}>  $violations
     */
    public function __construct(
        private readonly array $violations,
    ) {
        parent::__construct('Workflow attribute compilation failed validation.');
    }

    /**
     * @return list<array{rule: string, message: string}>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
