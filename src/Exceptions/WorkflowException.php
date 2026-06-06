<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

use RuntimeException;

/**
 * Base class for every exception thrown by the workflow engine.
 *
 * Hosts that want to catch ANY engine error should catch this class.
 * Catching this rather than the framework's RuntimeException ensures
 * that engine-internal errors (e.g. host code that re-uses a generic
 * RuntimeException) do not get accidentally caught.
 */
abstract class WorkflowException extends RuntimeException
{
    /**
     * Machine-readable error code. Used for logging, metrics, i18n.
     * Subclasses MUST set a stable, unique code.
     */
    public function errorCode(): string
    {
        return static::class;
    }

    /**
     * Optional structured context for logging/debugging. Subclasses MAY
     * override to add safe-to-log context (e.g. workflow key, instance id).
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }
}
