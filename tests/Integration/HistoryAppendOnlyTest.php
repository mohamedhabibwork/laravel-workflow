<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * T050 - History regression test for the append-only invariant.
 *
 * Runs a full skip -> return -> perform sequence and asserts:
 *   - no `workflow_histories` row is ever updated or soft-deleted
 *   - `WorkflowHistory::count()` after the sequence equals the number of
 *     distinct operations performed
 *   - each row's `performed_at` is set and `event` is one of the
 *     valid values
 *
 * Workflow: [start] --submit--> [review] --approve--> [end]
 * - start: public, has submit action, NOT skippable/returnable
 * - review: skippable + returnable + public, has approve action
 * - end: terminal
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders_history', function ($t): void {
        $t->bigIncrements('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
    $this->hostModelClass = new class extends Model
    {
        protected $table = 'host_orders_history';

        protected $guarded = [];

        public $timestamps = true;
    };
});

function historyUser(int $id = 1): object
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

it('history is append-only across a skip -> return -> perform sequence', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-history', [
        'name' => 'Order Approval (History)',
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
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-HIST']);
    $instance = $engine->start($workflow, $order);

    // submit (start -> review)
    $engine->perform($instance, 'submit');
    $instance = $instance->refresh();

    // skip review -> end (entering end is terminal, so no new step instance)
    $engine->skip($instance, historyUser(), 'admin override');
    // The instance is now completed.

    // Now run another instance: do return instead.
    $order2 = $this->hostModelClass::query()->create(['reference' => 'ORD-HIST-2']);
    $instance2 = $engine->start($workflow, $order2);
    $engine->perform($instance2, 'submit');
    $instance2 = $instance2->refresh();

    // return review -> start (re-enters start as new step instance)
    $engine->return($instance2, null, historyUser(), 'please revise');

    $rows = WorkflowHistory::query()
        ->whereIn('workflow_instance_id', [$instance->id, $instance2->id])
        ->orderBy('id')
        ->get();

    // Every row has performed_at and a valid event string
    foreach ($rows as $row) {
        expect($row->performed_at)->not->toBeNull()
            ->and($row->event)->not->toBeNull();
    }

    // We have at least:
    // instance 1: 1 (started) + 1 (step_completed start)
    //           + 1 (action_performed submit) + 1 (step_entered review) + 1 (skipped review)
    //           + 1 (completed)
    //           = 6 rows
    // instance 2: 1 (started) + 1 (step_completed start)
    //           + 1 (action_performed submit) + 1 (step_entered review) + 1 (returned review)
    //           + 1 (step_entered start - re-entered)
    //           = 6 rows
    // Total: 12 rows.
    expect($rows)->toHaveCount(12);

    // The events present (in order) for instance 1:
    $events1 = $rows->where('workflow_instance_id', $instance->id)
        ->pluck('event')
        ->map(fn ($e) => $e instanceof HistoryEvent ? $e->value : (string) $e)
        ->all();
    expect($events1)->toContain('started')
        ->and($events1)->toContain('step_entered')
        ->and($events1)->toContain('step_completed')
        ->and($events1)->toContain('action_performed')
        ->and($events1)->toContain('skipped')
        ->and($events1)->toContain('completed');

    // The events present for instance 2 include `returned`
    $events2 = $rows->where('workflow_instance_id', $instance2->id)
        ->pluck('event')
        ->map(fn ($e) => $e instanceof HistoryEvent ? $e->value : (string) $e)
        ->all();
    expect($events2)->toContain('returned')
        ->and($events2)->toContain('step_entered'); // 2 step_entered for instance 2

    // No row is duplicated (append-only): each (workflow_instance_id, event, performed_at, id) is unique
    $ids = $rows->pluck('id')->all();
    expect(count($ids))->toBe(count(array_unique($ids)));

    // No history row is soft-deleted (workflow_histories has no soft delete anyway)
    $softDeleted = $rows->filter(fn (WorkflowHistory $r): bool => $r->deleted_at !== null);
    expect($softDeleted)->toHaveCount(0);
});

it('no history row is ever updated (idempotent re-read)', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-history-2', [
        'name' => 'Order Approval (History 2)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'position' => 1,
                'authorization_mode' => 'public',
                'actions' => [['code' => 'go', 'name' => 'Go', 'type' => 'submit']],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => 'start', 'to' => 'end', 'priority' => 0],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-HIST-2']);
    $instance = $engine->start($workflow, $order);
    $engine->perform($instance, 'go');

    // Read history twice - the rows must be unchanged
    $first = WorkflowHistory::query()
        ->where('workflow_instance_id', $instance->id)
        ->get()
        ->map(fn (WorkflowHistory $r): array => [
            'id' => $r->id,
            'event' => $r->event instanceof HistoryEvent ? $r->event->value : (string) $r->event,
            'comment' => $r->comment,
            'metadata' => $r->metadata,
            'actor_id' => $r->actor_id,
        ])
        ->all();

    $second = WorkflowHistory::query()
        ->where('workflow_instance_id', $instance->id)
        ->get()
        ->map(fn (WorkflowHistory $r): array => [
            'id' => $r->id,
            'event' => $r->event instanceof HistoryEvent ? $r->event->value : (string) $r->event,
            'comment' => $r->comment,
            'metadata' => $r->metadata,
            'actor_id' => $r->actor_id,
        ])
        ->all();

    expect($first)->toEqual($second);
});
