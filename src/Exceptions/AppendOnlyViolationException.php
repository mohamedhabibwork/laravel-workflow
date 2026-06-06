<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Exceptions;

use LogicException;

/**
 * Thrown when code attempts to update or delete an append-only record.
 * LogicException (programmer error) — not a runtime error.
 */
final class AppendOnlyViolationException extends LogicException
{
}
