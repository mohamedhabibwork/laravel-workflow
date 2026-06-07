<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Enums\AssignmentStatus;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Exceptions\WorkflowTerminalException;
use HFlow\LaravelWorkflow\Models\WorkflowAssignment;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * T035 — Integration test for US3: approval-quorum behaviour.
 *
 *  (a) on a `match_mode = any` approval step with two pending assignments,
 *      the first `acted` assignment completes the step and the other
 *      pending assignment is marked `expired`
 *  (b) on a `match_mode = all` approval step with two pending assignments,
 *      the step completes only after both have been `acted` (see TODO below)
 *  (c) on terminal state, `perform` throws `WorkflowTerminalException`
 */

beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders_quorum', function ($t): void {
        $t->bigIncrements('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
    $this->hostModelClass = new class extends Model
    {
        protected $table = 'host_orders_quorum';

        protected $guarded = [];

        public $timestamps = true;
    };
});

/**
 * Minimal user stub — has a numeric key, no role/permission machinery.
 *
 * @param  int  $id
 */
function quorumUser(int $id): object
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

it('(a) match_mode = any: first acted completes the step, others expire', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-quorum-any', [
        'name' => 'Order Quorum (Any)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'actions' => [['code' => 'go', 'name' => 'Go', 'type' => 'submit']],
            ],
            [
                'key' => 'approve',
                'name' => 'Approve',
                'type' => 'approval',
                'authorization_mode' => 'public',
                'match_mode' => 'any',
                'assignees' => [
                    ['assignee_type' => 'user', 'assignee_key' => '1'],
                    ['assignee_type' => 'user', 'assignee_key' => '2'],
                ],
                'actions' => [['code' => 'yes', 'name' => 'Yes', 'type' => 'submit']],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'approve'],
            ['from' => 'approve', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-ANY']);
    $instance = $engine->start($workflow, $order);

    // Move past the start step
    $engine->perform($instance, 'go', quorumUser(1));

    // The approve step should now be active with 2 pending assignments
    $approveStepInstance = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('status', StepInstanceStatus::Active->value)
        ->first();
    expect($approveStepInstance)->not->toBeNull();

    $pendingCount = WorkflowAssignment::query()
        ->where('step_instance_id', $approveStepInstance->id)
        ->where('status', AssignmentStatus::Pending->value)
        ->count();
    expect($pendingCount)->toBe(2);

    // User 1 acts on the approval step
    $engine->perform($instance, 'yes', quorumUser(1));

    // The approve step instance is now in a terminal state
    $approveStepInstance->refresh();
    expect($approveStepInstance->status)->not->toBe(StepInstanceStatus::Active);

    // The other pending assignment is now expired
    $expiredCount = WorkflowAssignment::query()
        ->where('step_instance_id', $approveStepInstance->id)
        ->where('status', AssignmentStatus::Expired->value)
        ->count();
    expect($expiredCount)->toBe(1);

    // The instance reached the end step → completed
    $instance->refresh();
    expect($instance->status)->toBe(InstanceStatus::Completed);
});

it('(c) on terminal state, perform throws WorkflowTerminalException', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-terminal', [
        'name' => 'Order Terminal',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'actions' => [['code' => 'go', 'name' => 'Go', 'type' => 'submit']],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-TERM']);
    $instance = $engine->start($workflow, $order);

    // First perform completes the workflow
    $after = $engine->perform($instance, 'go', quorumUser(1));
    expect($after->status)->toBe(InstanceStatus::Completed);

    // Second perform on a completed instance throws
    expect(fn () => $engine->perform($after, 'go', quorumUser(1)))
        ->toThrow(WorkflowTerminalException::class);
});
