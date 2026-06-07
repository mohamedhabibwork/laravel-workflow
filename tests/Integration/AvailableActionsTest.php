<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Actions\ActionSet;
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * T033 — Integration test for US3: `availableActions`.
 *
 *  (a) on a `roles` step with the role held by the user, the set contains
 *      the action
 *  (b) on the same step with a user not holding the role, the set is empty
 *  (c) on a `conditional` action whose guard fails, the action is excluded
 *  (d) on a `custom` action whose handler returns false, the action is excluded
 *  (e) ordering is deterministic and stable across repeated calls (SC-002)
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders_avail', function ($t): void {
        $t->bigIncrements('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
    $this->hostModelClass = new class extends Model
    {
        protected $table = 'host_orders_avail';

        protected $guarded = [];

        public $timestamps = true;
    };
});

/**
 * Build a user stub that hasRole() returns true for the given list.
 *
 * @param  list<string>  $roles
 */
function rolesUser(array $roles): object
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

it('(a) on a `roles` step the user holding the role sees the actions', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-avail', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'actions' => [
                    ['code' => 'begin', 'name' => 'Begin', 'type' => 'submit'],
                ],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-A1']);
    $instance = $engine->start($workflow, $order);

    // The current step is `start` with public auth (default). `begin` is visible.
    $actions = $engine->availableActions($instance, rolesUser(['admin']));
    expect($actions)->toBeInstanceOf(ActionSet::class)
        ->and($actions->count())->toBe(1)
        ->and($actions->first()->key)->toBe('begin');
});

it('(b) on a `roles` step the user not holding the role sees an empty set', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-roles', [
        'name' => 'Order Approval (Roles)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'roles',
                'assignees' => [['assignee_type' => 'role', 'assignee_key' => 'manager']],
                'actions' => [
                    ['code' => 'begin', 'name' => 'Begin', 'type' => 'submit'],
                ],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-B1']);
    $instance = $engine->start($workflow, $order);

    // The `start` step is roles-restricted. The user does NOT have the role.
    $actions = $engine->availableActions($instance, rolesUser(['admin']));
    expect($actions->isEmpty())->toBeTrue();

    // The user WITH the role sees the action.
    $actions = $engine->availableActions($instance, rolesUser(['manager']));
    expect($actions->count())->toBe(1)
        ->and($actions->first()->key)->toBe('begin');
});

it('(c) excludes a `conditional` action whose guard fails', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-cond', [
        'name' => 'Order Approval (Conditional)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'actions' => [
                    [
                        'code' => 'approve_high',
                        'name' => 'Approve (high amount only)',
                        'type' => 'approve',
                        'availability_mode' => 'conditional',
                        'config' => [
                            'expression' => [
                                'op' => 'and',
                                'clauses' => [['field' => 'subject.amount', 'operator' => 'gt', 'value' => 1000]],
                            ],
                        ],
                    ],
                    [
                        'code' => 'approve',
                        'name' => 'Approve (always)',
                        'type' => 'approve',
                        'availability_mode' => 'general',
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

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-C1']);
    $instance = $engine->start($workflow, $order, ['amount' => 50]); // amount=50 < 1000

    $actions = $engine->availableActions($instance, null);

    // The conditional action fails (50 < 1000); the general one passes.
    expect($actions->count())->toBe(1)
        ->and($actions->first()->key)->toBe('approve');
});

it('(d) excludes a `custom` action whose handler returns false', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-custom', [
        'name' => 'Order Approval (Custom)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'actions' => [
                    [
                        'code' => 'special',
                        'name' => 'Special Action',
                        'type' => 'custom',
                        'availability_mode' => 'custom',
                        'handler' => CustomFalseHandler::class,
                    ],
                    [
                        'code' => 'regular',
                        'name' => 'Regular',
                        'type' => 'custom',
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

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-D1']);
    $instance = $engine->start($workflow, $order);

    $actions = $engine->availableActions($instance, null);

    // The custom-false action is excluded; only `regular` remains.
    expect($actions->count())->toBe(1)
        ->and($actions->first()->key)->toBe('regular');
});

it('(e) ordering is deterministic and stable across repeated calls (SC-002)', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval-order', [
        'name' => 'Order Approval (Order)',
        'type' => 'approval',
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'public',
                'actions' => [
                    ['code' => 'z_action', 'name' => 'Z', 'type' => 'custom', 'sort_order' => 30],
                    ['code' => 'a_action', 'name' => 'A', 'type' => 'custom', 'sort_order' => 10],
                    ['code' => 'm_action', 'name' => 'M', 'type' => 'custom', 'sort_order' => 20],
                ],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $this->hostModelClass::query()->create(['reference' => 'ORD-E1']);
    $instance = $engine->start($workflow, $order);

    $first = $engine->availableActions($instance, null);
    $second = $engine->availableActions($instance, null);

    expect($first->keys())->toBe(['a_action', 'm_action', 'z_action'])
        ->and($second->keys())->toBe($first->keys());
});

/**
 * Test stub for a custom action handler that always returns false.
 */
final class CustomFalseHandler
{
    public function isAvailable(array $context): bool
    {
        return false;
    }
}
