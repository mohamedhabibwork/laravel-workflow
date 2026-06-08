<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\CustomActionHandler;
use HFlow\LaravelWorkflow\Contracts\CustomAuthorizer;
use HFlow\LaravelWorkflow\Contracts\CustomConditionEvaluator;
use HFlow\LaravelWorkflow\Contracts\CustomResolver;
use HFlow\LaravelWorkflow\Contracts\CustomStepHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerInterface;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T073 — Integration tests for the host contracts.
 *
 * The engine resolves host contracts via FQCN strings stored on workflow
 * definitions (step.handler, step.custom_authorizer, action.handler,
 * assignee.custom_resolver). The contracts are bound in Laravel's container
 * and resolved by the engine at evaluation time.
 *
 * Each test below:
 *   1. defines a workflow with a FQCN pointing to a test-only class
 *   2. binds that FQCN in the container
 *   3. exercises the engine path
 *   4. verifies the test class was actually invoked
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders', function (Blueprint $t): void {
        $t->id('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
});

$hostModel = new class extends Model
{
    protected $table = 'host_orders';

    protected $guarded = [];

    public $timestamps = true;
};

it('exercises a host-supplied CustomActionHandler end-to-end', function () use ($hostModel): void {
    $tracker = new class
    {
        public int $calls = 0;

        /** @var array<int, array<string, mixed>> */
        public array $payloads = [];
    };

    // The engine resolves the action's handler by FQCN string from
    // `workflow_step_actions.handler`. The class must implement
    // CustomActionHandler (or expose a `handle()` method).
    $handler = new class($tracker) implements CustomActionHandler
    {
        /** @param object{calls: int, payloads: array<int, array<string, mixed>>} $tracker */
        public function __construct(private readonly object $tracker) {}

        public function handle(WorkflowInstance $instance, WorkflowStepAction $action, array $payload): void
        {
            $this->tracker->calls++;
            $this->tracker->payloads[] = $payload;
        }
    };

    $realFqcn = $handler::class;
    $this->app->instance($realFqcn, $handler);

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    // The action lives on the `start` step. The runner keeps the start
    // step active when it has actions, so `availableActions` exposes the
    // action and `perform` advances the instance to `end`.
    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModel::class,
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'actions' => [
                    ['code' => 'ack', 'name' => 'Acknowledge', 'type' => 'approve', 'handler' => $realFqcn],
                ],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $hostModel::query()->create(['reference' => 'CA-1']);
    $instance = $engine->start($workflow, $order);

    $actions = $engine->availableActions($instance);
    expect($actions->has('ack'))->toBeTrue();

    $engine->perform($instance, 'ack', ['id' => 1], ['comment' => 'looks good']);

    expect($tracker->calls)->toBe(1);
    expect($tracker->payloads[0])->toMatchArray(['comment' => 'looks good']);
});

it('exercises a host-supplied CustomStepHandler end-to-end', function () use ($hostModel): void {
    $tracker = new class
    {
        public int $calls = 0;
    };

    $handler = new class($tracker) implements CustomStepHandler
    {
        /** @param object{calls: int} $tracker */
        public function __construct(private readonly object $tracker) {}

        public function handle(WorkflowInstance $instance, WorkflowStepInstance $stepInstance): array
        {
            $this->tracker->calls++;

            return ['side_effect_ran' => true];
        }
    };

    $realFqcn = $handler::class;
    $this->app->instance($realFqcn, $handler);

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModel::class,
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            [
                'key' => 'compute', 'name' => 'Compute', 'type' => 'automated',
                'handler' => $realFqcn,
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'compute'],
            ['from' => 'compute', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $hostModel::query()->create(['reference' => 'SH-1']);
    $instance = $engine->start($workflow, $order);

    // The automation runner has fired the handler.
    expect($tracker->calls)->toBeGreaterThanOrEqual(1);
});

it('exercises a host-supplied AuthorizerInterface (custom_authorizer column) end-to-end', function () use ($hostModel): void {
    $allowed = new class implements AuthorizerInterface
    {
        public function mode(): AuthorizationMode
        {
            return AuthorizationMode::Custom;
        }

        public function authorize(mixed $user, WorkflowInstance $instance, WorkflowStepInstance $stepInstance, WorkflowStep $step): bool
        {
            return true;
        }
    };

    $denied = new class implements AuthorizerInterface
    {
        public function mode(): AuthorizationMode
        {
            return AuthorizationMode::Custom;
        }

        public function authorize(mixed $user, WorkflowInstance $instance, WorkflowStepInstance $stepInstance, WorkflowStep $step): bool
        {
            return false;
        }
    };

    $allowedFqcn = $allowed::class;
    $deniedFqcn = $denied::class;
    $this->app->instance($allowedFqcn, $allowed);
    $this->app->instance($deniedFqcn, $denied);

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    // Each invocation of `define` produces a fresh V1 row, so the two
    // workflows below live as separate V1 drafts. Each is activated
    // independently. Both put the action on the `start` step, which the
    // runner keeps active when actions are present. The `custom_authorizer`
    // on the start step decides whether the action is available.
    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModel::class,
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'custom',
                'custom_authorizer' => $deniedFqcn,
                'actions' => [
                    ['code' => 'ok', 'name' => 'Approve', 'type' => 'approve'],
                ],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $hostModel::query()->create(['reference' => 'CA-2']);
    $instance = $engine->start($workflow, $order);

    // With the denying authorizer, 'ok' is NOT available.
    $actions = $engine->availableActions($instance, (object) ['id' => 1]);
    expect($actions->has('ok'))->toBeFalse();

    // Re-define the workflow with the allowing authorizer.
    $workflow2 = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModel::class,
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'authorization_mode' => 'custom',
                'custom_authorizer' => $allowedFqcn,
                'actions' => [
                    ['code' => 'ok', 'name' => 'Approve', 'type' => 'approve'],
                ],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow2);

    $order2 = $hostModel::query()->create(['reference' => 'CA-3']);
    $instance2 = $engine->start($workflow2, $order2);

    $actions2 = $engine->availableActions($instance2->refresh(), (object) ['id' => 1]);
    expect($actions2->has('ok'))->toBeTrue();
});

it('exercises a host-supplied CustomResolver (callable) end-to-end', function () use ($hostModel): void {
    $tracker = new class
    {
        public int $calls = 0;

        /** @var list<int> */
        public array $ids = [];
    };

    // The engine treats the `custom_resolver` value as a no-arg callable
    // FQCN: it resolves the class via Laravel's container, then calls
    // `$impl()` and casts the return to array<int>. The class is therefore
    // expected to be `is_callable` (i.e. implement `__invoke`).
    $resolver = new class($tracker)
    {
        /** @param object{calls: int, ids: list<int>} $tracker */
        public function __construct(private readonly object $tracker) {}

        /** @return list<int> */
        public function __invoke(): array
        {
            $this->tracker->calls++;
            $this->tracker->ids = [101, 102, 103];

            return $this->tracker->ids;
        }
    };

    $realFqcn = $resolver::class;
    $this->app->instance($realFqcn, $resolver);

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'subject_type' => $hostModel::class,
        'steps' => [
            [
                'key' => 'start',
                'name' => 'Start',
                'type' => 'start',
                'actions' => [
                    ['code' => 'ok', 'name' => 'Approve', 'type' => 'approve'],
                ],
            ],
            [
                'key' => 'review',
                'name' => 'Review',
                'type' => 'task',
                'assignees' => [
                    ['assignee_type' => 'custom', 'assignee_key' => 'team', 'custom_resolver' => $realFqcn],
                ],
            ],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [
            ['from' => '__start__', 'to' => 'review'],
            ['from' => 'review', 'to' => 'end'],
        ],
    ]);
    $engine->activate($workflow);

    $order = $hostModel::query()->create(['reference' => 'CR-1']);
    $instance = $engine->start($workflow, $order);

    // `start()` opens the start step instance but does NOT materialize
    // assignments for it. The materializer is invoked by `perform()`
    // when transitioning into the next step. So we perform `ok` to
    // open the `review` step, which materializes its custom-resolver
    // assignees.
    $engine->perform($instance, 'ok');

    expect($tracker->calls)->toBeGreaterThanOrEqual(1);
});

it('exposes the 5 host contract interfaces from the Contracts namespace', function (): void {
    // This test is a sanity check: every host contract in src/Contracts/
    // exists and is an interface.
    $contracts = [
        CustomActionHandler::class,
        CustomAuthorizer::class,
        CustomConditionEvaluator::class,
        CustomResolver::class,
        CustomStepHandler::class,
    ];

    foreach ($contracts as $contract) {
        expect(interface_exists($contract))->toBeTrue("{$contract} should be an interface");
    }
});
