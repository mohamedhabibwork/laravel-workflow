<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Exceptions\InvalidWorkflowException;
use HFlow\LaravelWorkflow\Exceptions\WorkflowSubjectMismatchException;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * T027 — Integration test for US2: start a workflow instance on a host model.
 *
 *  (a) start on a host Order returns a WorkflowInstance with status=in_progress,
 *      workflow_version pinned, workflowable morphTo resolves to the Order.
 *  (b) start on a non-active workflow throws InvalidWorkflowException.
 *  (c) start on a subject whose class is not the workflow's subject_type
 *      throws WorkflowSubjectMismatchException.
 *  (d) currentStep returns the start step instance with entered_at set and
 *      due_at computed from sla_seconds.
 *  (e) a Started history entry is appended with the initiator as the actor
 *      (or actor_type=system when null).
 */

/**
 * A minimal host model for tests. Uses a real Eloquent model in SQLite memory
 * so morphTo resolution works.
 */
$hostModelClass = new class extends Model
{
    protected $table = 'host_orders';

    protected $guarded = [];

    public $timestamps = true;
};

beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders', function ($t): void {
        $t->bigIncrements('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
});

it('(a) starts a workflow instance on a host model record with version pinned', function () use ($hostModelClass): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModelClass::class,
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'review', 'name' => 'Manager Review', 'type' => 'task', 'sla_seconds' => 3600],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'review'],
            ['from' => 'review', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $hostModelClass::query()->create(['reference' => 'ORD-001']);

    $instance = $engine->start($workflow, $order, ['amount' => 2500], initiator: null);

    expect($instance)
        ->toBeInstanceOf(WorkflowInstance::class)
        ->and($instance->status)->toBe(InstanceStatus::InProgress)
        ->and($instance->workflow_version)->toBe(1)
        ->and($instance->workflow_id)->toBe($workflow->id)
        ->and($instance->subject_type)->toBe($hostModelClass::class)
        ->and($instance->subject_id)->toBe($order->id)
        ->and($instance->started_at)->not->toBeNull();

    // morphTo resolves
    $resolved = $instance->workflowable;
    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($order->id);
});

it('(b) refuses to start a non-active workflow', function () use ($hostModelClass): void {
    $engine = app(WorkflowEngine::class);

    $draft = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);

    $order = $hostModelClass::query()->create(['reference' => 'ORD-002']);

    expect(fn () => $engine->start($draft, $order))->toThrow(InvalidWorkflowException::class);
});

it('(c) refuses to start an instance with the wrong subject class', function () use ($hostModelClass): void {
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModelClass::class,
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($workflow);

    $other = new class extends Model
    {
        protected $table = 'host_invoices';

        protected $guarded = [];

        public $timestamps = true;
    };
    Schema::create('host_invoices', function ($t): void {
        $t->bigIncrements('id');
        $t->string('number')->nullable();
        $t->timestamps();
    });
    $invoice = $other::query()->create(['number' => 'INV-1']);

    expect(fn () => $engine->start($workflow, $invoice))
        ->toThrow(WorkflowSubjectMismatchException::class);
});

it('(d) currentStep returns the start step instance with entered_at and due_at', function () use ($hostModelClass): void {
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModelClass::class,
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start', 'sla_seconds' => 600],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($workflow);

    $order = $hostModelClass::query()->create(['reference' => 'ORD-003']);
    $instance = $engine->start($workflow, $order);

    $current = $engine->currentStep($instance);

    expect($current)
        ->toBeInstanceOf(WorkflowStepInstance::class)
        ->and($current->status)->toBe(StepInstanceStatus::Active)
        ->and($current->entered_at)->not->toBeNull()
        ->and($current->due_at)->not->toBeNull();

    // due_at should be ~600s after entered_at
    $diff = abs($current->entered_at->diffInSeconds($current->due_at));
    expect($diff)->toBeGreaterThanOrEqual(599)
        ->and($diff)->toBeLessThanOrEqual(601);
});

it('(e) appends a Started history entry with the initiator as the actor', function () use ($hostModelClass): void {
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModelClass::class,
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($workflow);

    $order = $hostModelClass::query()->create(['reference' => 'ORD-004']);
    $initiator = $hostModelClass::query()->create(['reference' => 'USR-1']);

    $instance = $engine->start($workflow, $order, [], initiator: $initiator);

    $history = WorkflowHistory::query()
        ->where('workflow_instance_id', $instance->id)
        ->cursor();

    expect($history)->toHaveCount(1)
        ->and($history->first()->event)->toBe(HistoryEvent::Started)
        ->and($history->first()->actor_type)->toBe(ActorType::User)
        ->and($history->first()->actor_id)->toBe($initiator->id);
});

it('(e) uses actor_type=system when no initiator is provided', function () use ($hostModelClass): void {
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModelClass::class,
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($workflow);

    $order = $hostModelClass::query()->create(['reference' => 'ORD-005']);
    $instance = $engine->start($workflow, $order);

    $history = WorkflowHistory::query()
        ->where('workflow_instance_id', $instance->id)
        ->first();

    expect($history->event)->toBe(HistoryEvent::Started)
        ->and($history->actor_type)->toBe(ActorType::System)
        ->and($history->actor_id)->toBeNull();
});
