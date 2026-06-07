<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Exceptions\ReturnNotAllowedException;
use HFlow\LaravelWorkflow\Exceptions\SkipNotAllowedException;
use HFlow\LaravelWorkflow\Exceptions\WorkflowTerminalException;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * T049 - Integration test for US4: `skip` and `return`.
 *
 *  (a) skip requires `WorkflowStep.is_skippable = true` (else SkipNotAllowedException)
 *  (b) skip routes per the skip transition (explicit target_step else next by position)
 *  (c) return requires `WorkflowStep.is_returnable = true` (else ReturnNotAllowedException)
 *  (d) return re-enters the target step as a NEW step instance
 *  (e) both skip and return append new history events without mutating prior history
 *
 * Workflow: [start] --submit--> [review] --approve--> [end]
 * - start: public, has submit action
 * - review: is_skippable=true, is_returnable=true, has approve action
 * - end: terminal
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders_skip', function ($t): void {
        $t->bigIncrements('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
    $this->hostModelClass = new class extends Model
    {
        protected $table = 'host_orders_skip';

        protected $guarded = [];

        public $timestamps = true;
    };
});

function skipReturnUser(int $id = 1): object
{
    return new class($id)
    {
        public function __construct(private int $id) {}

        public function getKey(): int
        {
            return $this->id;
        }
    };
}

function buildSkipReturnWorkflow(): array
{
    return [
        'name' => 'Order Approval (Skip+Return)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'position' => 1,
                'authorization_mode' => 'public',
                'actions' => [['code' => 'submit', 'name' => 'Submit', 'type' => 'submit']],
            ],
            [
                'key' => 'review',
                'name' => 'Review',
                'type' => 'task',
                'position' => 2,
                'is_skippable' => true,
                'is_returnable' => true,
                'authorization_mode' => 'public',
                'actions' => [['code' => 'approve', 'name' => 'Approve', 'type' => 'approve']],
            ],
            [
                'key' => 'end',
                'name' => 'End',
                'type' => 'end',
                'position' => 3,
            ],
        ],
        'transitions' => [
            ['from' => 'start', 'to' => 'review', 'priority' => 0],
            ['from' => 'review', 'to' => 'end', 'priority' => 0],
        ],
    ];
}

function startOnReview(): array
{
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-skip-return', buildSkipReturnWorkflow());
    $engine->activate($workflow);

    $order = test()->hostModelClass::query()->create(['reference' => 'ORD-SR']);
    $instance = $engine->start($workflow, $order);
    $engine->perform($instance, 'submit');

    return [$engine, $instance->refresh()];
}

it('(a) skip on a non-skippable step throws SkipNotAllowedException', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-skip-non', [
        'name' => 'Order Approval (No Skip)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'is_skippable' => false,
                'actions' => [['code' => 'begin', 'name' => 'Begin', 'type' => 'submit']],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-NSKIP']);
    $instance = $engine->start($workflow, $order);

    $countBefore = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();

    expect(fn () => $engine->skip($instance, skipReturnUser(), 'because'))
        ->toThrow(SkipNotAllowedException::class);

    $instance->refresh();
    expect($instance->status->value)->toBe('in_progress');

    $countAfter = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();
    expect($countAfter)->toBe($countBefore);
});

it('(b) skip routes to the next step by position (sequential fallback)', function (): void {
    [$engine, $instance] = startOnReview();

    $result = $engine->skip($instance, skipReturnUser(), 'admin override');
    $result->refresh();

    $leaving = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('action_taken', 'skip')
        ->first();
    expect($leaving)->not->toBeNull()
        ->and($leaving->status)->toBe(StepInstanceStatus::Skipped)
        ->and($leaving->comment)->toBe('admin override');

    expect($result->status->value)->toBe('completed');
});

it('(b2) skip routes per the explicit skip transition when present', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $def = buildSkipReturnWorkflow();
    $def['transitions'][] = [
        'from' => 'review',
        'to' => 'end',
        'type' => 'skip',
        'priority' => 10,
    ];

    $workflow = $engine->define('order-approval-skip-explicit', $def);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-SKIP-EX']);
    $instance = $engine->start($workflow, $order);
    $engine->perform($instance, 'submit');

    $result = $engine->skip($instance->refresh(), skipReturnUser());
    $result->refresh();

    expect($result->status->value)->toBe('completed');
});

it('(c) return on a non-returnable step throws ReturnNotAllowedException', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-return-non', [
        'name' => 'Order Approval (No Return)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'is_returnable' => false,
                'actions' => [['code' => 'go', 'name' => 'Go', 'type' => 'submit']],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-NRET']);
    $instance = $engine->start($workflow, $order);

    $countBefore = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();

    expect(fn () => $engine->return($instance, null, skipReturnUser(), 'please'))
        ->toThrow(ReturnNotAllowedException::class);

    $instance->refresh();
    expect($instance->status->value)->toBe('in_progress');

    $countAfter = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();
    expect($countAfter)->toBe($countBefore);
});

it('(d) return re-enters the target step as a NEW active step instance', function (): void {
    [$engine, $instance] = startOnReview();

    $result = $engine->return($instance, null, skipReturnUser(), 'fix and resubmit');
    $result->refresh();

    $active = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('status', StepInstanceStatus::Active->value)
        ->first();
    expect($active)->not->toBeNull();

    $returned = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('status', StepInstanceStatus::Returned->value)
        ->first();
    expect($returned)->not->toBeNull()
        ->and($returned->action_taken)->toBe('return')
        ->and($returned->comment)->toBe('fix and resubmit');

    $startSteps = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->whereHas('step', fn ($q) => $q->where('code', 'start'))
        ->get();
    expect($startSteps)->toHaveCount(2)
        ->and($startSteps->where('status', StepInstanceStatus::Completed->value))->toHaveCount(1)
        ->and($startSteps->where('status', StepInstanceStatus::Active->value))->toHaveCount(1);
});

it('(e) both skip and return append new history events without mutating prior', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    [$engine, $instance] = startOnReview();
    $engine->perform($instance->refresh(), 'approve');

    $countBefore = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();

    // Instance is now completed - skip throws WorkflowTerminalException
    expect(fn () => $engine->skip($instance->refresh(), skipReturnUser()))
        ->toThrow(WorkflowTerminalException::class);

    // The count of history rows has not changed
    $countAfter = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();
    expect($countAfter)->toBe($countBefore);

    // All history rows have non-null performed_at
    $rows = WorkflowHistory::query()
        ->where('workflow_instance_id', $instance->id)
        ->get();
    foreach ($rows as $row) {
        expect($row->performed_at)->not->toBeNull();
    }
});
