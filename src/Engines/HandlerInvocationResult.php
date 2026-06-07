<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use Throwable;

/**
 * Discriminated union of success/failure for a host handler invocation.
 *
 *   - success: the handler ran and returned an array (possibly empty)
 *   - failure: the handler threw; the original Throwable is preserved
 *
 * Used by `HandlerInvoker` so that `perform()` and the automation runner
 * can decide how to react without try/catch leaking across boundaries.
 */
final readonly class HandlerInvocationResult
{
    private function __construct(
        public bool $success,
        public array $data,
        public ?Throwable $throwable,
    ) {}

    public static function success(array $data): self
    {
        return new self(true, $data, null);
    }

    public static function failure(Throwable $throwable): self
    {
        return new self(false, [], $throwable);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return ! $this->success;
    }
}
