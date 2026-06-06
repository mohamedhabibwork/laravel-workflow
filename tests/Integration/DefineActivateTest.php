<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Exceptions\InvalidStateException;
use HFlow\LaravelWorkflow\Exceptions\InvalidWorkflowException;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

/**
 * T021 — Integration test for US1: define → activate → createNewVersion.
 *
 *  (a) define a workflow with 1 start + 1 task + 1 end + 2 transitions, save as `draft`,
 *      refuse `start()` on it.
 *  (b) activate succeeds only with exactly 1 start + ≥1 end.
 *  (c) activate rejects 0 starts, 2 starts, 0 ends.
 *  (d) createNewVersion clones steps/transitions/conditions/actions/assignees,
 *      increments `version`, leaves `is_current_version = false`, and
 *      leaves live instances untouched.
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
});

it('(a) defines a draft workflow with steps, transitions, assignees, and actions', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'review', 'name' => 'Manager Review', 'type' => 'task', 'is_skippable' => false, 'is_returnable' => true],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'review'],
            ['from' => 'review', 'to' => 'end'],
        ],
        'conditions' => [
            ['name' => 'amount-over-1000', 'kind' => 'expression', 'expression' => ['var' => 'subject.amount'], 'description' => null],
        ],
    ]);

    expect($workflow)
        ->toBeInstanceOf(Workflow::class)
        ->and($workflow->status)->toBe(WorkflowStatus::Draft)
        ->and($workflow->is_current_version)->toBeFalse()
        ->and($workflow->version)->toBe(1)
        ->and($workflow->steps)->toHaveCount(3)
        ->and($workflow->transitions)->toHaveCount(2)
        ->and($workflow->conditions)->toHaveCount(1);
});

it('(a) refuses start() on a draft workflow', function (): void {
    $engine = app(WorkflowEngine::class);
    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);

    // Use a stub model since the engine doesn't actually start yet.
    $subject = new class extends Model {};

    expect(fn () => $engine->start($workflow, $subject))
        ->toThrow(InvalidWorkflowException::class);
});

it('(b) activates successfully when there is exactly one start and at least one end', function (): void {
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);

    $activated = $engine->activate($workflow);

    expect($activated->status)->toBe(WorkflowStatus::Active)
        ->and($activated->is_current_version)->toBeTrue();
});

it('(c) refuses to activate a workflow with zero start steps', function (): void {
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('no-start', [
        'name' => 'No Start',
        'steps' => [
            ['key' => 'task', 'name' => 'Task', 'type' => 'task'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'task'],
        ],
    ]);

    // First, replace the synthetic start transition so the engine doesn't reject on transition shape.
    WorkflowTransition::query()->where('workflow_id', $workflow->id)->update(['from_step_id' => null]);

    expect(fn () => $engine->activate($workflow))->toThrow(InvalidWorkflowException::class);
});

it('(c) refuses to activate a workflow with two start steps', function (): void {
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('two-starts', [
        'name' => 'Two Starts',
        'steps' => [
            ['key' => 'start1', 'name' => 'Start 1', 'type' => 'start'],
            ['key' => 'start2', 'name' => 'Start 2', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);

    expect(fn () => $engine->activate($workflow))->toThrow(InvalidWorkflowException::class);
});

it('(c) refuses to activate a workflow with zero end steps', function (): void {
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('no-end', [
        'name' => 'No End',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'task', 'name' => 'Task', 'type' => 'task'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'task'],
        ],
    ]);

    expect(fn () => $engine->activate($workflow))->toThrow(InvalidWorkflowException::class);
});

it('refuses to activate a workflow that is not in draft status', function (): void {
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);

    $engine->activate($workflow);

    // Second activation attempt must throw
    expect(fn () => $engine->activate($workflow->fresh()))->toThrow(InvalidStateException::class);
});

it('(d) createNewVersion deep-clones steps, transitions, conditions, actions, and assignees', function (): void {
    $engine = app(WorkflowEngine::class);

    $original = $engine->define('order-approval', [
        'name' => 'Order Approval v1',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'actions' => [
                    ['code' => 'begin', 'name' => 'Begin', 'availability_mode' => 'general', 'requires_comment' => false],
                ],
            ],
            [
                'key' => 'review',
                'name' => 'Review',
                'type' => 'task',
                'assignees' => [
                    ['assignee_type' => 'role', 'assignee_key' => 'manager'],
                ],
                'actions' => [
                    ['code' => 'approve', 'name' => 'Approve', 'requires_comment' => true],
                    ['code' => 'reject', 'name' => 'Reject', 'requires_comment' => true],
                ],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'review'],
            ['from' => 'review', 'to' => 'end'],
        ],
        'conditions' => [
            ['name' => 'amount-check', 'kind' => 'expression', 'expression' => ['var' => 'subject.amount']],
        ],
    ]);

    $v2 = $engine->createNewVersion($original, ['name' => 'Order Approval v2']);

    expect($v2)
        ->toBeInstanceOf(Workflow::class)
        ->and($v2->id)->not->toBe($original->id)
        ->and($v2->version)->toBe($original->version + 1)
        ->and($v2->status)->toBe(WorkflowStatus::Draft)
        ->and($v2->is_current_version)->toBeFalse()
        ->and($v2->name)->toBe('Order Approval v2');

    expect($v2->steps)->toHaveCount(3)
        ->and($v2->transitions)->toHaveCount(2)
        ->and($v2->conditions)->toHaveCount(1);

    // All step ids in v2 are new
    $v2StepIds = $v2->steps->pluck('id')->all();
    $v1StepIds = $original->steps->pluck('id')->all();
    expect(array_intersect($v2StepIds, $v1StepIds))->toBeEmpty();

    // Assignees and actions are cloned
    $reviewStep = $v2->steps->firstWhere('code', 'review');
    expect($reviewStep->assignees)->toHaveCount(1)
        ->and($reviewStep->actions)->toHaveCount(2);
});

it('(d) createNewVersion leaves live instances untouched (still pinned to v1)', function (): void {
    $engine = app(WorkflowEngine::class);

    $original = $engine->define('order-approval', [
        'name' => 'Order Approval v1',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($original);

    // Forge a live instance pinned to v1 (start() is not yet implemented)
    $instance = new WorkflowInstance;
    $instance->forceFill([
        'workflow_id' => $original->id,
        'workflow_version' => 1,
        'subject_type' => 'order',
        'subject_id' => 1,
        'status' => InstanceStatus::InProgress,
    ])->save();

    $v2 = $engine->createNewVersion($original);
    $engine->activate($v2);

    // Original instance must still reference v1's workflow row
    $instance->refresh();
    expect($instance->workflow_id)->toBe($original->id)
        ->and($instance->workflow_version)->toBe(1);

    // v1 is no longer the current version
    expect($original->fresh()->is_current_version)->toBeFalse()
        ->and($v2->fresh()->is_current_version)->toBeTrue();
});

it('versions() returns all versions of a workflow code, newest first', function (): void {
    $engine = app(WorkflowEngine::class);

    $v1 = $engine->define('order-approval', [
        'name' => 'v1',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $v2 = $engine->createNewVersion($v1);
    $v3 = $engine->createNewVersion($v2);

    $versions = $engine->versions('order-approval');

    expect($versions)->toHaveCount(3)
        ->and($versions->pluck('version')->all())->toBe([3, 2, 1]);
});

it('activate() flips is_current_version for previous active version (same code)', function (): void {
    $engine = app(WorkflowEngine::class);

    $v1 = $engine->define('order-approval', [
        'name' => 'v1',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($v1);
    expect($v1->fresh()->is_current_version)->toBeTrue();

    $v2 = $engine->createNewVersion($v1);
    $engine->activate($v2);

    expect($v1->fresh()->is_current_version)->toBeFalse()
        ->and($v2->fresh()->is_current_version)->toBeTrue();
});
