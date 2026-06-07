<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Contracts\CustomActionHandler;
use HFlow\LaravelWorkflow\Contracts\CustomStepHandler;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Throwable;

/**
 * Single class that resolves a host-supplied FQCN and invokes the matching
 * handler contract method:
 *
 *   - `step.type = automated`  → `CustomStepHandler::handle()`
 *   - any other step           → `CustomActionHandler::handle()`
 *
 * Wrapped in a try/catch that returns a {@see HandlerInvocationResult}.
 * The orchestrator (`perform()`, `AutomationRunner`) can then decide
 * whether to fail the step, retry, or skip without try/catch leaking
 * across the public API.
 */
final class HandlerInvoker
{
    /**
     * Invoke the step handler (if any) for a `step.type = automated` step.
     *
     * The handler returns an array that is merged into the step instance's
     * `data` column by the orchestrator.
     *
     * @return HandlerInvocationResult
     */
    public function invokeStep(WorkflowStep $step, WorkflowStepInstance $stepInstance): HandlerInvocationResult
    {
        $fqcn = $step->handler;
        if (! is_string($fqcn) || $fqcn === '' || ! class_exists($fqcn)) {
            return HandlerInvocationResult::success([]);
        }

        try {
            $impl = app($fqcn);
        } catch (Throwable $e) {
            return HandlerInvocationResult::failure($e);
        }

        if (! $impl instanceof CustomStepHandler) {
            return HandlerInvocationResult::failure(new \LogicException(
                "Step handler [{$fqcn}] must implement ".CustomStepHandler::class,
            ));
        }

        return $this->guard(
            static fn (): array => $impl->handle($stepInstance->instance, $stepInstance),
        );
    }

    /**
     * Invoke the action handler (if any) for a performed action.
     *
     * @param  array<string, mixed>  $payload  Caller-supplied payload (comment, metadata, etc.)
     * @return HandlerInvocationResult
     */
    public function invokeAction(
        WorkflowStepAction $action,
        WorkflowInstance $instance,
        array $payload,
    ): HandlerInvocationResult {
        $fqcn = $action->handler;
        if (! is_string($fqcn) || $fqcn === '' || ! class_exists($fqcn)) {
            return HandlerInvocationResult::success([]);
        }

        try {
            $impl = app($fqcn);
        } catch (Throwable $e) {
            return HandlerInvocationResult::failure($e);
        }

        if (! $impl instanceof CustomActionHandler) {
            return HandlerInvocationResult::failure(new \LogicException(
                "Action handler [{$fqcn}] must implement ".CustomActionHandler::class,
            ));
        }

        return $this->guard(
            static function () use ($impl, $instance, $action, $payload): array {
                $impl->handle($instance, $action, $payload);

                return [];
            },
        );
    }

    /**
     * @param  callable():array<string, mixed>  $fn
     */
    private function guard(callable $fn): HandlerInvocationResult
    {
        try {
            $result = $fn();

            return HandlerInvocationResult::success(is_array($result) ? $result : []);
        } catch (Throwable $e) {
            return HandlerInvocationResult::failure($e);
        }
    }
}
