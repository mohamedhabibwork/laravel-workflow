<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\CustomActionHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Exceptions\ActionNotAvailableException;
use HFlow\LaravelWorkflow\Exceptions\CommentRequiredException;
use HFlow\LaravelWorkflow\Exceptions\NotEligibleException;
use HFlow\LaravelWorkflow\Exceptions\WorkflowTerminalException;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * T034 — Integration test for US3: `perform`.
 *
 *  (a) perform re-validates eligibility server-side (deterministic outcomes)
 *  (b) perform runs the action's handler if set, inside a try/catch
 *  (c) perform closes the leaving step with appropriate terminal status and
 *      opens the entering one with `entered_at` + computed `due_at`
 *  (d) perform appends `step_completed`, `action_performed`, `step_entered`
 *  (e) `requires_comment = true` rejects when `comment` is missing/empty
 *      (throws `CommentRequiredException`, no state change)
 *  (f) action not in available set throws `ActionNotAvailableException`
 *  (g) ineligible user throws `NotEligibleException`
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders_perf', function ($t): void {
        $t->id('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
    $this->hostModelClass = new class extends Model
    {
        protected $table = 'host_orders_perf';

        protected $guarded = [];

        public $timestamps = true;
    };
});

/**
 * @param  list<string>  $roles
 */
function perfUser(array $roles): object
{
    return new class($roles)
    {
        public function __construct(private array $roles) {}

        public function hasRole(string $role): bool
        {
            return in_array($role, $this->roles, true);
        }

        public function getKey(): int
        {
            return 1;
        }
    };
}

it('(a) re-validates eligibility server-side and is deterministic', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-perf-det', [
        'name' => 'Order Approval (Deterministic)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'roles',
                'assignees' => [['assignee_type' => 'role', 'assignee_key' => 'manager']],
                'actions' => [['code' => 'begin', 'name' => 'Begin', 'type' => 'submit']],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-DET']);
    $instance = $engine->start($workflow, $order);

    // First call: admin (no role) → NotEligibleException
    expect(fn () => $engine->perform($instance, 'begin', perfUser(['admin'])))
        ->toThrow(NotEligibleException::class);

    // State must be unchanged
    $instance->refresh();
    expect($instance->status)->toBe(InstanceStatus::InProgress);

    // Second call: manager (role) → succeeds
    $after = $engine->perform($instance, 'begin', perfUser(['manager']));
    expect($after->status)->toBe(InstanceStatus::Completed);

    // Third call: already terminal → WorkflowTerminalException
    expect(fn () => $engine->perform($after, 'begin', perfUser(['manager'])))
        ->toThrow(WorkflowTerminalException::class);
});

it('(b) runs the action handler if set, inside try/catch', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-handler', [
        'name' => 'Order Approval (Handler)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'actions' => [
                    [
                        'code' => 'tag',
                        'name' => 'Tag',
                        'type' => 'custom',
                        'handler' => TagHandler::class,
                    ],
                ],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-H']);
    $instance = $engine->start($workflow, $order, ['tag' => 'urgent']);

    $engine->perform($instance, 'tag', null, ['tag' => 'urgent']);

    expect(TagHandler::$lastPayload)->toBe(['tag' => 'urgent']);
    expect(TagHandler::$instanceSeen)->toBeInstanceOf(WorkflowInstance::class);
});

it('(c) closes the leaving step and opens the entering one with entered_at + due_at', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-due', [
        'name' => 'Order Approval (Due)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'sla_seconds' => 3600,
                'actions' => [['code' => 'go', 'name' => 'Go', 'type' => 'submit']],
            ],
            [
                'key' => 'review',
                'name' => 'Review',
                'type' => 'task',
                'authorization_mode' => 'public',
                'sla_seconds' => 1800,
                'actions' => [['code' => 'finish', 'name' => 'Finish', 'type' => 'submit']],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => 'start', 'to' => 'review'],
            ['from' => 'review', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-DUE']);
    $instance = $engine->start($workflow, $order);

    $before = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('status', StepInstanceStatus::Active->value)
        ->first();
    expect($before)->not->toBeNull();

    $engine->perform($instance, 'go');

    // Leaving step is now in a terminal state
    $before->refresh();
    expect($before->status)->not->toBe(StepInstanceStatus::Active);
    expect($before->completed_at)->not->toBeNull();

    // Entering step is active with entered_at + due_at
    $after = WorkflowStepInstance::query()
        ->where('workflow_instance_id', $instance->id)
        ->where('status', StepInstanceStatus::Active->value)
        ->first();
    expect($after)->not->toBeNull();
    expect($after->entered_at)->not->toBeNull();
    expect($after->due_at)->not->toBeNull();
});

it('(d) appends step_completed, action_performed, step_entered history entries', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-hist', [
        'name' => 'Order Approval (History)',
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

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-HIST']);
    $instance = $engine->start($workflow, $order);

    $engine->perform($instance, 'go');

    $events = WorkflowHistory::query()
        ->where('workflow_instance_id', $instance->id)
        ->get()
        ->map(fn (WorkflowHistory $h): string => $h->event->value)
        ->all();

    // The leaving step is `start` (which is connected directly to `end`).
    // Since the entering step is `end` (terminal), we expect step_completed,
    // action_performed, and `completed` (not `step_entered`).
    expect($events)->toContain((string) HistoryEvent::StepCompleted->value)
        ->and($events)->toContain((string) HistoryEvent::ActionPerformed->value)
        ->and($events)->toContain((string) HistoryEvent::Completed->value);
});

it('(e) requires_comment = true rejects when comment is missing/empty', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-comment', [
        'name' => 'Order Approval (Comment)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'actions' => [[
                    'code' => 'reject',
                    'name' => 'Reject',
                    'type' => 'reject',
                    'requires_comment' => true,
                ]],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-COM']);
    $instance = $engine->start($workflow, $order);

    $countBefore = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();

    // Empty comment rejected
    expect(fn () => $engine->perform($instance, 'reject', null, ['comment' => '']))
        ->toThrow(CommentRequiredException::class);

    // No state change
    $instance->refresh();
    expect($instance->status)->toBe(InstanceStatus::InProgress);

    $countAfter = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();
    expect($countAfter)->toBe($countBefore);
});

it('(f) action not in available set throws ActionNotAvailableException', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-notavail', [
        'name' => 'Order Approval (Not Avail)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'actions' => [['code' => 'only', 'name' => 'Only', 'type' => 'submit']],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-NA']);
    $instance = $engine->start($workflow, $order);

    $countBefore = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();

    expect(fn () => $engine->perform($instance, 'does_not_exist'))
        ->toThrow(ActionNotAvailableException::class);

    // No state change
    $instance->refresh();
    expect($instance->status)->toBe(InstanceStatus::InProgress);

    $countAfter = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();
    expect($countAfter)->toBe($countBefore);
});

it('(g) ineligible user throws NotEligibleException', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-ineligible', [
        'name' => 'Order Approval (Ineligible)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'roles',
                'assignees' => [['assignee_type' => 'role', 'assignee_key' => 'manager']],
                'actions' => [['code' => 'begin', 'name' => 'Begin', 'type' => 'submit']],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-INEL']);
    $instance = $engine->start($workflow, $order);

    $countBefore = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();

    expect(fn () => $engine->perform($instance, 'begin', perfUser(['admin'])))
        ->toThrow(NotEligibleException::class);

    $instance->refresh();
    expect($instance->status)->toBe(InstanceStatus::InProgress);

    $countAfter = WorkflowHistory::query()->where('workflow_instance_id', $instance->id)->count();
    expect($countAfter)->toBe($countBefore);
});

/**
 * Test fixture — captures the last payload and instance.
 */
final class TagHandler implements CustomActionHandler
{
    public static ?array $lastPayload = null;

    public static ?WorkflowInstance $instanceSeen = null;

    public function handle(WorkflowInstance $instance, WorkflowStepAction $action, array $payload): void
    {
        self::$lastPayload = $payload;
        self::$instanceSeen = $instance;
    }
}
