<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Tests\Fixtures;

use HFlow\LaravelWorkflow\Contracts\CustomStepHandler;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use RuntimeException;

/**
 * Test fixture: a `CustomStepHandler` that records every call and (optionally)
 * throws on demand. Used by the automation pipeline integration tests.
 *
 * Behavior is controlled by per-step `WorkflowStep.config`:
 *   - `result` (string):  the value merged into the returned data
 *   - `throw` (bool):     when truthy, throws a RuntimeException
 *   - `throw_message` (string): optional exception message
 *
 * State is held in static arrays so it persists across the in-memory test
 * run. Tests must call {@see self::reset()} in their setup hooks.
 */
final class RecordingStepHandler implements CustomStepHandler
{
    /**
     * @var list<array{instance_id: int, step_id: int, step_code: string, data: array<string, mixed>, result: array<string, mixed>}>
     */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function handle(
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
    ): array {
        $step = $stepInstance->step;
        $config = is_array($step?->config) ? $step->config : [];

        $result = ['result' => (string) ($config['result'] ?? 'ok')];

        self::$calls[] = [
            'instance_id' => (int) $instance->getKey(),
            'step_id' => (int) $step->getKey(),
            'step_code' => (string) $step->code,
            'data' => $config,
            'result' => $result,
        ];

        if (! empty($config['throw'])) {
            throw new RuntimeException((string) ($config['throw_message'] ?? 'Forced failure'));
        }

        return $result;
    }
}

/**
 * Test fixture: a `CustomStepHandler` that throws on the first call and
 * succeeds on subsequent calls. Used to verify `retry()` re-enters a failed
 * step as a fresh step instance.
 */
final class RetryableStepHandler implements CustomStepHandler
{
    /**
     * Map of step_id → number of completed invocations (only the first one throws).
     *
     * @var array<int, int>
     */
    public static array $completed = [];

    public static function reset(): void
    {
        self::$completed = [];
    }

    public function handle(
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
    ): array {
        $stepId = (int) $stepInstance->step->getKey();
        $count = self::$completed[$stepId] ?? 0;

        if ($count === 0) {
            self::$completed[$stepId] = 1;

            throw new RuntimeException('First attempt fails on purpose');
        }

        self::$completed[$stepId] = $count + 1;

        return ['attempts' => $count + 1];
    }
}
