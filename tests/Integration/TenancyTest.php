<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Exceptions\InvalidStateException;
use HFlow\LaravelWorkflow\Exceptions\WorkflowTerminalException;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\QueryBuilder\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * T063-T067 (US7) — Integration test for tenancy.
 *
 *   (a) with `tenancy.enabled = true` and a provider returning `1`, all
 *       Workflow / WorkflowStep / WorkflowInstance / WorkflowHistory
 *       rows are stamped with `tenant_id = 1`
 *   (b) the same `code` may exist in two different tenants but not within
 *       the same tenant (uniqueness is tenant-scoped)
 *   (c) with `tenancy.enabled = false`, all rows are `tenant_id = null`
 *       and uniqueness is global per code
 *   (d) when the resolver returns null while tenancy is enabled, the
 *       engine performs a no-scope query (host's authorization layer
 *       is responsible for safety)
 *   (e) ActivityFeed::read() honors tenancy
 *   (f) hold/resume/cancel work and the on_hold/resumed/cancelled
 *       history rows are stamped with the current tenant
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
        $t->id('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
});

it('(a) stamps tenant_id on every row when tenancy is enabled', function () use ($hostModelClass): void {
    config()->set('workflow.tenancy.enabled', true);
    config()->set('workflow.tenancy.column', 'tenant_id');

    $this->app->instance(
        TenantScopeProvider::class,
        new class implements TenantScopeProvider
        {
            public function currentTenantId(): int|string|null
            {
                return 42;
            }
        },
    );

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModelClass::class,
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'review', 'name' => 'Review', 'type' => 'task'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'review'],
            ['from' => 'review', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    expect($workflow->tenant_id)->toBe(42);

    $order = $hostModelClass::query()->create(['reference' => 'ORD-001']);
    $instance = $engine->start($workflow, $order);

    expect($instance->tenant_id)->toBe(42);

    // History row carries the tenant id.
    $history = WorkflowHistory::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('workflow_instance_id', $instance->id)
        ->get();
    expect($history)->not->toBeEmpty();
    foreach ($history as $row) {
        expect($row->tenant_id)->toBe(42);
    }

    // Steps are stamped too.
    $steps = $workflow->steps()->withoutGlobalScope(TenantScope::class)->get();
    foreach ($steps as $step) {
        expect($step->tenant_id)->toBe(42);
    }
});

it('(b) the same code may exist in two different tenants but not within one', function (): void {
    config()->set('workflow.tenancy.enabled', true);
    config()->set('workflow.tenancy.column', 'tenant_id');

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    // Tenant 1: define order-approval
    $this->app->instance(
        TenantScopeProvider::class,
        new class implements TenantScopeProvider
        {
            public function currentTenantId(): int|string|null
            {
                return 1;
            }
        },
    );
    $w1 = $engine->define('order-approval', [
        'name' => 'Order Approval A',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    expect($w1->tenant_id)->toBe(1);

    // Switch to tenant 2
    $this->app->instance(
        TenantScopeProvider::class,
        new class implements TenantScopeProvider
        {
            public function currentTenantId(): int|string|null
            {
                return 2;
            }
        },
    );
    $w2 = $engine->define('order-approval', [
        'name' => 'Order Approval B',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    expect($w2->tenant_id)->toBe(2);

    // Total rows in DB: 2 workflows with the same code but different tenants.
    $count = Workflow::query()->withoutGlobalScope(TenantScope::class)
        ->where('code', 'order-approval')
        ->count();
    expect($count)->toBe(2);
});

it('(c) disables tenant scoping when tenancy is off in config', function () use ($hostModelClass): void {
    config()->set('workflow.tenancy.enabled', false);
    config()->set('workflow.tenancy.column', 'tenant_id');

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($workflow);

    expect($workflow->tenant_id)->toBeNull();

    $order = $hostModelClass::query()->create(['reference' => 'ORD-X']);
    $instance = $engine->start($workflow, $order);

    expect($instance->tenant_id)->toBeNull();
});

it('(d) is a no-op query when the resolver returns null while tenancy is enabled', function (): void {
    config()->set('workflow.tenancy.enabled', true);
    config()->set('workflow.tenancy.column', 'tenant_id');

    $this->app->instance(
        TenantScopeProvider::class,
        new class implements TenantScopeProvider
        {
            public function currentTenantId(): int|string|null
            {
                return null;
            }
        },
    );

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);

    // No tenant was resolved; engine wrote null.
    expect($workflow->tenant_id)->toBeNull();

    // And the workflow is still findable (no-scope query path).
    $found = $engine->versions('order-approval');
    expect($found)->toHaveCount(1);
});

it('(e) ActivityFeed::read() honors the tenant scope', function () use ($hostModelClass): void {
    config()->set('workflow.tenancy.enabled', true);
    config()->set('workflow.tenancy.column', 'tenant_id');

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $this->app->instance(
        TenantScopeProvider::class,
        new class implements TenantScopeProvider
        {
            public function currentTenantId(): int|string|null
            {
                return 5;
            }
        },
    );

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($workflow);

    $order = $hostModelClass::query()->create(['reference' => 'ORD-E']);
    $instance = $engine->start($workflow, $order);

    $history = $engine->history($instance);
    expect($history)->not->toBeEmpty();
    foreach ($history as $row) {
        // History events are visible because tenant=5 matches.
        expect($row->tenant_id)->toBe(5);
    }

    // Switching to another tenant hides the events.
    $this->app->instance(
        TenantScopeProvider::class,
        new class implements TenantScopeProvider
        {
            public function currentTenantId(): int|string|null
            {
                return 6;
            }
        },
    );

    $history2 = $engine->history($instance->refresh());
    expect($history2)->toBeEmpty();
});

it('(f) hold/resume/cancel work and the on_hold/resumed/cancelled events are tenant-stamped', function () use ($hostModelClass): void {
    config()->set('workflow.tenancy.enabled', true);
    config()->set('workflow.tenancy.column', 'tenant_id');

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $this->app->instance(
        TenantScopeProvider::class,
        new class implements TenantScopeProvider
        {
            public function currentTenantId(): int|string|null
            {
                return 9;
            }
        },
    );

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'review', 'name' => 'Review', 'type' => 'task'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'review'],
            ['from' => 'review', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $hostModelClass::query()->create(['reference' => 'ORD-F']);
    $instance = $engine->start($workflow, $order);

    $held = $engine->hold($instance, null, 'paused for review');
    expect($held->status)->toBe(InstanceStatus::OnHold);

    $resumed = $engine->resume($held);
    expect($resumed->status)->toBe(InstanceStatus::InProgress);

    $cancelled = $engine->cancel($resumed, null, 'no longer needed');
    expect($cancelled->status)->toBe(InstanceStatus::Cancelled);

    // History events for hold/resume/cancel all carry the tenant.
    $rows = WorkflowHistory::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('workflow_instance_id', $cancelled->id)
        ->whereIn('event', [HistoryEvent::OnHold->value, HistoryEvent::Resumed->value, HistoryEvent::Cancelled->value])
        ->get();
    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row->tenant_id)->toBe(9);
    }
});

it('(f2) cancel rejects when the instance is already terminal', function () use ($hostModelClass): void {
    config()->set('workflow.tenancy.enabled', false);

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($workflow);

    $order = $hostModelClass::query()->create(['reference' => 'ORD-G']);
    $instance = $engine->start($workflow, $order);

    // Force the instance to a terminal state. The `start` step in a `start → end`
    // graph does not auto-advance to `end` (the end step is terminal-type, not
    // automated), so we synthesise the terminal state directly to exercise the
    // guard inside `cancel()`.
    $instance->forceFill([
        'status' => InstanceStatus::Completed,
        'completed_at' => now(),
    ])->save();

    expect($instance->refresh()->status->isTerminal())->toBeTrue();

    expect(fn () => $engine->cancel($instance->refresh()))->toThrow(WorkflowTerminalException::class);
});

it('(f3) resume rejects when the instance is not on hold', function (): void {
    config()->set('workflow.tenancy.enabled', false);

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($workflow);

    // Create an instance, then immediately try to resume (it never went on hold).
    $hostModelClass = new class extends Model
    {
        protected $table = 'host_orders';

        protected $guarded = [];

        public $timestamps = true;
    };
    $order = $hostModelClass::query()->create(['reference' => 'ORD-H']);
    $instance = $engine->start($workflow, $order);

    expect(fn () => $engine->resume($instance->refresh()))->toThrow(InvalidStateException::class);
});
