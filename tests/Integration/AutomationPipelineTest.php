<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\CustomStepHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Exceptions\AutomationLoopGuardException;
use HFlow\LaravelWorkflow\Exceptions\InvalidStateException;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * T054 — Integration test for US5: automation pipeline (T054–T058).
 *
 *  (a) an `automation` workflow with all `automated` steps until an `end`
 *      step reaches `completed` in a single `start()` call
 *  (b) an automated step whose handler throws sets the step instance and
 *      the instance to `failed` and records an `error` history event
 *  (c) a chain that reaches a human-gated step pauses at that step and
 *      remains queryable
 *  (d) `retry()` re-enters the failed step as a fresh step instance and
 *      resumes the chain
 *  (e) a chain that exceeds `config('workflow.automation.max_chain_depth')`
 *      throws `AutomationLoopGuardException`
 */
final class StepHandlerAlpha implements CustomStepHandler
{
    public static int $invocations = 0;

    public static array $data = [];

    public static function reset(): void
    {
        self::$invocations = 0;
        self::$data = [];
    }

    public function handle(WorkflowInstance $instance, WorkflowStepInstance $stepInstance): array
    {
        self::$invocations++;
        self::$data[] = $stepInstance->step->code;

        return ['handler' => 'alpha', 'step' => $stepInstance->step->code];
    }
}

final class StepHandlerBeta implements CustomStepHandler
{
    public static int $invocations = 0;

    public static function reset(): void
    {
        self::$invocations = 0;
    }

    public function handle(WorkflowInstance $instance, WorkflowStepInstance $stepInstance): array
    {
        self::$invocations++;

        return ['handler' => 'beta'];
    }
}

final class StepHandlerThrows implements CustomStepHandler
{
    public static int $invocations = 0;

    public static function reset(): void
    {
        self::$invocations = 0;
    }

    public function handle(WorkflowInstance $instance, WorkflowStepInstance $stepInstance): array
    {
        self::$invocations++;

        throw new RuntimeException('handler explosion');
    }
}

final class StepHandlerRecovers implements CustomStepHandler
{
    public static int $invocations = 0;

    public static function reset(): void
    {
        self::$invocations = 0;
    }

    public function handle(WorkflowInstance $instance, WorkflowStepInstance $stepInstance): array
    {
        self::$invocations++;

        if (self::$invocations === 1) {
            throw new RuntimeException('first attempt fails');
        }

        return ['recovered' => true];
    }
}

beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders_auto', function ($t): void {
        $t->bigIncrements('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
    $this->hostModelClass = new class extends Model
    {
        protected $table = 'host_orders_auto';

        protected $guarded = [];

        public $timestamps = true;
    };

    StepHandlerAlpha::reset();
    StepHandlerBeta::reset();
    StepHandlerThrows::reset();
    StepHandlerRecovers::reset();

    $this->app->instance(StepHandlerAlpha::class, new StepHandlerAlpha);
    $this->app->instance(StepHandlerBeta::class, new StepHandlerBeta);
    $this->app->instance(StepHandlerThrows::class, new StepHandlerThrows);
    $this->app->instance(StepHandlerRecovers::class, new StepHandlerRecovers);
});

it('(a) chains all-automated steps to completed in a single start()', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('etl-pipeline', [
        'name' => 'ETL Pipeline',
        'type' => 'automation',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'extract', 'name' => 'Extract', 'type' => 'automated', 'handler' => StepHandlerAlpha::class],
            ['key' => 'transform', 'name' => 'Transform', 'type' => 'automated', 'handler' => StepHandlerBeta::class],
            ['key' => 'load', 'name' => 'Load', 'type' => 'automated', 'handler' => StepHandlerBeta::class],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'start'],
            ['from' => 'start', 'to' => 'extract'],
            ['from' => 'extract', 'to' => 'transform'],
            ['from' => 'transform', 'to' => 'load'],
            ['from' => 'load', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'AUTO-1']);

    $instance = $engine->start($workflow, $order);

    expect($instance->status)->toBe(InstanceStatus::Completed)
        ->and($instance->completed_at)->not->toBeNull()
        ->and($instance->current_step_id)->toBe($workflow->steps()->where('code', 'end')->first()->id);

    // The full chain is: extract → transform → load
    expect(StepHandlerAlpha::$invocations)->toBe(1)   // extract
        ->and(StepHandlerBeta::$invocations)->toBe(2);   // transform + load

    // History contains the full chain
    $events = WorkflowHistory::query()
        ->where('workflow_instance_id', $instance->id)
        ->pluck('event')
        ->map(fn ($e) => $e instanceof HistoryEvent ? $e->value : (string) $e)
        ->all();

    expect($events)->toContain(HistoryEvent::Started->value)
        ->and($events)->toContain(HistoryEvent::StepCompleted->value)
        ->and($events)->toContain(HistoryEvent::StepEntered->value)
        ->and($events)->toContain(HistoryEvent::Completed->value);
});

it('(b) marks the step and instance as failed when a handler throws', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('flaky', [
        'name' => 'Flaky Pipeline',
        'type' => 'automation',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'do_work', 'name' => 'Do Work', 'type' => 'automated', 'handler' => StepHandlerThrows::class],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'start'],
            ['from' => 'start', 'to' => 'do_work'],
            ['from' => 'do_work', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'FAIL-1']);

    $instance = $engine->start($workflow, $order);

    expect($instance->status)->toBe(InstanceStatus::Failed)
        ->and($instance->completed_at)->not->toBeNull();

    $failedStep = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('status', StepInstanceStatus::Failed->value)
        ->first();

    expect($failedStep)->not->toBeNull()
        ->and($failedStep->comment)->toBe('handler explosion')
        ->and($failedStep->action_taken)->toBe('automation_failure');

    $errorEvent = WorkflowHistory::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('event', HistoryEvent::Error->value)
        ->first();

    expect($errorEvent)->not->toBeNull()
        ->and($errorEvent->comment)->toBe('handler explosion')
        ->and(($errorEvent->metadata['error_class'] ?? null))->toBe(RuntimeException::class)
        ->and(($errorEvent->metadata['automation'] ?? null))->toBeTrue();
});

it('(c) pauses at a human-gated step and leaves the instance queryable', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('auto-then-human', [
        'name' => 'Auto then Human',
        'type' => 'automation',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'compute', 'name' => 'Compute', 'type' => 'automated', 'handler' => StepHandlerAlpha::class],
            [
                'key' => 'review',
                'name' => 'Manager Review',
                'type' => 'task',
                'authorization_mode' => 'public',
                'actions' => [['code' => 'approve', 'name' => 'Approve', 'type' => 'approve']],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'start'],
            ['from' => 'start', 'to' => 'compute'],
            ['from' => 'compute', 'to' => 'review'],
            ['from' => 'review', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'PAUSE-1']);
    $instance = $engine->start($workflow, $order);

    expect($instance->status)->toBe(InstanceStatus::InProgress);

    $current = $engine->currentStep($instance);
    expect($current)->toBeInstanceOf(WorkflowStepInstance::class);
    $currentStep = $current->step;
    expect($currentStep->code)->toBe('review')
        ->and($currentStep->type->value)->toBe('task');

    // Exactly one automated step ran before pausing
    expect(StepHandlerAlpha::$invocations)->toBe(1);

    // The instance is queryable, history shows the pause
    $events = WorkflowHistory::query()
        ->where('workflow_instance_id', $instance->id)
        ->orderBy('id')
        ->pluck('event')
        ->map(fn ($e) => $e instanceof HistoryEvent ? $e->value : (string) $e)
        ->all();

    expect($events[0])->toBe(HistoryEvent::Started->value)
        ->and(end($events))->toBe(HistoryEvent::StepEntered->value);
});

it('(d) retry() re-enters the failed step as a fresh step instance and resumes the chain', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('retry-chain', [
        'name' => 'Retry Chain',
        'type' => 'automation',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'first', 'name' => 'First', 'type' => 'automated', 'handler' => StepHandlerRecovers::class],
            ['key' => 'second', 'name' => 'Second', 'type' => 'automated', 'handler' => StepHandlerAlpha::class],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'start'],
            ['from' => 'start', 'to' => 'first'],
            ['from' => 'first', 'to' => 'second'],
            ['from' => 'second', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'RETRY-1']);
    $instance = $engine->start($workflow, $order);

    // First run: RecoveringStepHandler throws on the first step
    expect($instance->status)->toBe(InstanceStatus::Failed)
        ->and(StepHandlerRecovers::$invocations)->toBe(1);

    $failedStepRow = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('status', StepInstanceStatus::Failed->value)
        ->first();
    $failedStepId = (int) $failedStepRow->step_id;

    $retried = $engine->retry($instance, comment: 'trying again');

    // After retry: instance reaches completed
    expect($retried->status)->toBe(InstanceStatus::Completed)
        ->and(StepHandlerRecovers::$invocations)->toBeGreaterThanOrEqual(2)
        ->and(StepHandlerAlpha::$invocations)->toBe(1);

    // Two distinct step instances for the failed step: one failed, one completed
    $first = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('step_id', $failedStepId)
        ->orderBy('id')
        ->first();

    $reentered = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('step_id', $failedStepId)
        ->orderByDesc('id')
        ->first();

    expect($first->status)->toBe(StepInstanceStatus::Failed)
        ->and($reentered->status)->toBe(StepInstanceStatus::Completed)
        ->and($first->id)->not->toBe($reentered->id);

    // The retry appended a `step_entered` row with `retry => true` in metadata
    $retryEvent = WorkflowHistory::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('event', HistoryEvent::StepEntered->value)
        ->where('metadata->retry', true)
        ->first();

    expect($retryEvent)->not->toBeNull()
        ->and($retryEvent->comment)->toBe('trying again');
});

it('(d2) retry() on a non-failed instance throws InvalidStateException', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('simple', [
        'name' => 'Simple',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'INPROG-1']);
    $instance = $engine->start($workflow, $order);

    expect(fn () => $engine->retry($instance))->toThrow(InvalidStateException::class);
});

it('(e) throws AutomationLoopGuardException when the chain exceeds max_chain_depth', function (): void {
    config()->set('workflow.automation.max_chain_depth', 3);

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    // Loop: start -> loop_a -> loop_b -> loop_a -> loop_b -> ...
    $workflow = $engine->define('infinite', [
        'name' => 'Infinite Chain',
        'type' => 'automation',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'loop_a', 'name' => 'Loop A', 'type' => 'automated', 'handler' => StepHandlerAlpha::class],
            ['key' => 'loop_b', 'name' => 'Loop B', 'type' => 'automated', 'handler' => StepHandlerBeta::class],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'start'],
            ['from' => 'start', 'to' => 'loop_a'],
            ['from' => 'loop_a', 'to' => 'loop_b'],
            ['from' => 'loop_b', 'to' => 'loop_a'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'LOOP-1']);

    expect(fn () => $engine->start($workflow, $order))->toThrow(AutomationLoopGuardException::class);
});
