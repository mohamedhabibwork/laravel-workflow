<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * T059 - Integration test for US6: `history()` (the activity feed).
 *
 *  (a) full sequence: start → submit → approve → complete: events in
 *      chronological order (asc) with actor, event, comment, fromStep, toStep.
 *  (b) skipped/returned event: fromStep, toStep, comment are present.
 *  (c) limit: at most `$limit` rows, most recent first (desc).
 *  (d) event filter: only the named event is returned.
 *  (e) no caching: a new event appears on the next read.
 *  (f) append-only: no row appears twice in the feed.
 *
 * Workflow: [start] --submit--> [review] --approve--> [end]
 *   - start: public, has submit action
 *   - review: is_skippable=true, is_returnable=true, has approve action
 *   - end:   terminal
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders_feed', function ($t): void {
        $t->bigIncrements('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
    $this->hostModelClass = new class extends Model
    {
        protected $table = 'host_orders_feed';

        protected $guarded = [];

        public $timestamps = true;
    };
});

function feedUser(int $id = 7): object
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

function buildFeedWorkflow(): array
{
    return [
        'name' => 'Order Approval (Activity Feed)',
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

function feedEvent(WorkflowHistory $row): string
{
    $e = $row->event;

    return $e instanceof HistoryEvent ? $e->value : (string) $e;
}

it('(a) full sequence is returned in chronological asc order with relations loaded', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-feed-full', buildFeedWorkflow());
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-FEED-A']);
    $instance = $engine->start($workflow, $order, [], feedUser(11));
    $engine->perform($instance->refresh(), 'submit', feedUser(11), ['comment' => 'submitted by test']);
    $engine->perform($instance->refresh(), 'approve', feedUser(12), ['comment' => 'approved by reviewer']);
    $instance = $instance->refresh();

    $feed = $engine->history($instance);

    // start (1) + (step_completed start + action_performed submit + step_entered review) (3)
    // + (step_completed review + action_performed approve + completed) (3) = 7
    // (note: terminal `end` step emits `completed`, not `step_entered`)
    expect($feed)->toHaveCount(7);

    // All rows belong to this instance
    foreach ($feed as $row) {
        expect($row->workflow_instance_id)->toBe($instance->id);
    }

    // Order: asc by performed_at (and id as tiebreaker). First row is `started`.
    $events = $feed->map(fn (WorkflowHistory $r): string => feedEvent($r))->all();
    expect($events[0])->toBe('started')
        ->and($events[count($events) - 1])->toBe('completed');

    // Strictly non-decreasing by id (asc within the same performed_at second)
    $ids = $feed->pluck('id')->map(fn ($v): int => (int) $v)->all();
    $sorted = $ids;
    sort($sorted);
    expect($ids)->toBe($sorted);

    // fromStep / toStep relations are eager-loaded on rows that have from_step_id / to_step_id.
    // The very first row is `started` and has no from/to (engine doesn't set them on start),
    // so we look at any row that has them populated.
    $withRelations = $feed->first(fn (WorkflowHistory $r): bool => $r->from_step_id !== null);
    expect($withRelations)->not->toBeNull()
        ->and($withRelations->getRelation('fromStep'))->toBeInstanceOf(Model::class)
        ->and($withRelations->getRelation('toStep'))->toBeInstanceOf(Model::class);

    // Every row has a non-null actor and a performed_at timestamp
    foreach ($feed as $row) {
        expect($row->actor_id)->not->toBeNull()
            ->and($row->performed_at)->not->toBeNull();
    }
});

it('(b) skipped event carries fromStep, toStep and comment', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-feed-skip', buildFeedWorkflow());
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-FEED-B']);
    $instance = $engine->start($workflow, $order, [], feedUser(21));
    $engine->perform($instance->refresh(), 'submit', feedUser(21));

    $engine->skip($instance->refresh(), feedUser(22), 'admin override');

    $feed = $engine->history($instance->refresh());
    $skipped = $feed->first(fn (WorkflowHistory $r): bool => feedEvent($r) === 'skipped');

    expect($skipped)->not->toBeNull()
        ->and($skipped->comment)->toBe('admin override')
        ->and($skipped->fromStep)->not->toBeNull()
        ->and($skipped->fromStep->code)->toBe('review')
        ->and($skipped->toStep)->not->toBeNull()
        ->and($skipped->toStep->code)->toBe('end')
        ->and($skipped->relationLoaded('fromStep'))->toBeTrue()
        ->and($skipped->relationLoaded('toStep'))->toBeTrue();
});

it('(b2) returned event carries fromStep, toStep and comment', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-feed-return', buildFeedWorkflow());
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-FEED-B2']);
    $instance = $engine->start($workflow, $order, [], feedUser(31));
    $engine->perform($instance->refresh(), 'submit', feedUser(31));

    $engine->return($instance->refresh(), null, feedUser(32), 'fix and resubmit');

    $feed = $engine->history($instance->refresh());
    $returned = $feed->first(fn (WorkflowHistory $r): bool => feedEvent($r) === 'returned');

    expect($returned)->not->toBeNull()
        ->and($returned->comment)->toBe('fix and resubmit')
        ->and($returned->fromStep)->not->toBeNull()
        ->and($returned->fromStep->code)->toBe('review')
        ->and($returned->toStep)->not->toBeNull()
        ->and($returned->toStep->code)->toBe('start');
});

it('(c) limit returns at most N rows, most recent first (desc)', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-feed-limit', buildFeedWorkflow());
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-FEED-C']);
    $instance = $engine->start($workflow, $order, [], feedUser(41));
    $engine->perform($instance->refresh(), 'submit', feedUser(41));
    $engine->perform($instance->refresh(), 'approve', feedUser(42));
    $instance = $instance->refresh();

    $total = $engine->history($instance)->count();
    expect($total)->toBeGreaterThan(3);

    $limited = $engine->history($instance, limit: 3);

    expect($limited)->toHaveCount(3);

    // Most-recent first: desc by id (and performed_at)
    $ids = $limited->pluck('id')->map(fn ($v): int => (int) $v)->all();
    $expected = $ids;
    rsort($expected);
    expect($ids)->toBe($expected);

    // The 3 most recent ids are a strict subset of all ids, and the latest
    // row of the limited slice is the very last row of the full feed.
    $allIds = $engine->history($instance)->pluck('id')->map(fn ($v): int => (int) $v)->all();
    $latestId = (int) end($allIds);
    expect((int) $limited->first()->id)->toBe($latestId)
        ->and(count(array_diff($ids, $allIds)))->toBe(0);
});

it('(d) event filter returns only the named event', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-feed-filter', buildFeedWorkflow());
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-FEED-D']);
    $instance = $engine->start($workflow, $order, [], feedUser(51));
    $engine->perform($instance->refresh(), 'submit', feedUser(51));
    $engine->perform($instance->refresh(), 'approve', feedUser(52));
    $instance = $instance->refresh();

    $performed = $engine->history($instance, event: HistoryEvent::ActionPerformed->value);

    expect($performed->count())->toBeGreaterThan(0);
    foreach ($performed as $row) {
        expect(feedEvent($row))->toBe('action_performed');
    }

    // A different filter returns a strictly different set
    $started = $engine->history($instance, event: HistoryEvent::Started->value);
    expect($started->count())->toBe(1)
        ->and(feedEvent($started->first()))->toBe('started');
});

it('(e) feed reflects new events on the next read (no caching)', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-feed-live', buildFeedWorkflow());
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-FEED-E']);
    $instance = $engine->start($workflow, $order, [], feedUser(61));
    $engine->perform($instance->refresh(), 'submit', feedUser(61));
    $instance = $instance->refresh();

    $first = $engine->history($instance);
    $firstCount = $first->count();
    $firstIds = $first->pluck('id')->map(fn ($v): int => (int) $v)->all();

    // New event(s) appended after the read
    $engine->perform($instance->refresh(), 'approve', feedUser(62));
    $instance = $instance->refresh();

    $second = $engine->history($instance);
    $secondCount = $second->count();
    $secondIds = $second->pluck('id')->map(fn ($v): int => (int) $v)->all();

    // New rows exist on the second read
    expect($secondCount)->toBeGreaterThan($firstCount);

    // The first read's id set is a strict subset of the second read's id set
    $diff = array_diff($firstIds, $secondIds);
    expect($diff)->toBe([])
        ->and(count(array_diff($secondIds, $firstIds)))->toBeGreaterThan(0);
});

it('(f) feed never contains a row twice (append-only invariant)', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-feed-unique', buildFeedWorkflow());
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-FEED-F']);
    $instance = $engine->start($workflow, $order, [], feedUser(71));
    $engine->perform($instance->refresh(), 'submit', feedUser(71));
    $engine->skip($instance->refresh(), feedUser(72), 'override');
    $instance = $instance->refresh();

    $feed = $engine->history($instance);
    $ids = $feed->pluck('id')->map(fn ($v): int => (int) $v)->all();

    expect(count($ids))->toBe(count(array_unique($ids)));
});
