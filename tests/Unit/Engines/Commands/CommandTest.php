<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * T069 — Unit tests for the 4 Artisan commands.
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders', function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->string('reference')->nullable();
        $t->timestamps();
    });
});

it('workflow:list renders an empty table when no workflows exist', function (): void {
    $exit = Artisan::call('workflow:list');
    $output = Artisan::output();
    expect($exit)->toBe(0);
    expect($output)->toContain('No workflows found.');
});

it('workflow:list renders a table with one row per workflow + current version', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $w1 = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($w1);

    $exit = Artisan::call('workflow:list');
    $output = Artisan::output();
    expect($exit)->toBe(0);
    expect($output)->toContain('order-approval');
    expect($output)->toContain('Order Approval');
    expect($output)->toContain('active');
});

it('workflow:status shows the current step + history tail of an instance', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $hostModel = new class extends Model
    {
        protected $table = 'host_orders';

        protected $guarded = [];

        public $timestamps = true;
    };

    $w = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($w);

    $order = $hostModel::query()->create(['reference' => 'CMD-1']);
    $instance = $engine->start($w, $order);

    $exit = Artisan::call('workflow:status', ['instance' => $instance->uuid]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain($instance->uuid);
    expect($output)->toContain('order-approval');
    expect($output)->toContain('started');
});

it('workflow:status fails gracefully when the instance does not exist', function (): void {
    $exit = Artisan::call('workflow:status', ['instance' => 'no-such-uuid']);
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('No workflow instance');
});

it('workflow:history prints the activity feed as a table', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $hostModel = new class extends Model
    {
        protected $table = 'host_orders';

        protected $guarded = [];

        public $timestamps = true;
    };

    $w = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($w);

    $order = $hostModel::query()->create(['reference' => 'CMD-2']);
    $instance = $engine->start($w, $order);

    $exit = Artisan::call('workflow:history', ['instance' => $instance->uuid, '--limit' => 5]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('Activity feed');
    expect($output)->toContain('started');
});

it('workflow:diagnose reports missing start/end steps', function (): void {
    // Define a workflow with no start or end step, bypassing the engine.
    $workflow = Workflow::query()->create([
        'code' => 'no-starts',
        'name' => 'No Starts',
        'type' => 'generic',
        'status' => 'active',
        'version' => 1,
        'is_current_version' => true,
    ]);
    WorkflowStep::query()->create([
        'workflow_id' => $workflow->id,
        'name' => 'Middle',
        'code' => 'middle',
        'type' => 'task',
        'position' => 1,
    ]);

    $exit = Artisan::call('workflow:diagnose', ['workflow' => 'no-starts']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('Missing');
    expect($output)->toContain('start');
    expect($output)->toContain('end');
});

it('workflow:diagnose reports OK for a well-formed workflow', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $w = $engine->define('order-approval', [
        'name' => 'Order Approval',
        'type' => 'approval',
        'steps' => [
            ['key' => 'start', 'name' => 'Start', 'type' => 'start'],
            ['key' => 'end', 'name' => 'End', 'type' => 'end'],
        ],
        'transitions' => [['from' => '__start__', 'to' => 'end']],
    ]);
    $engine->activate($w);

    $exit = Artisan::call('workflow:diagnose', ['workflow' => 'order-approval']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('order-approval');
    expect($output)->toContain('OK');
});

it('workflow:diagnose reports actions without handlers', function (): void {
    // Define a workflow with an action that has no handler.
    $workflow = Workflow::query()->create([
        'code' => 'no-handler',
        'name' => 'No Handler',
        'type' => 'generic',
        'status' => 'active',
        'version' => 1,
        'is_current_version' => true,
    ]);
    $start = WorkflowStep::query()->create([
        'workflow_id' => $workflow->id,
        'name' => 'Start',
        'code' => 'start',
        'type' => 'start',
        'position' => 1,
    ]);
    $end = WorkflowStep::query()->create([
        'workflow_id' => $workflow->id,
        'name' => 'End',
        'code' => 'end',
        'type' => 'end',
        'position' => 2,
    ]);
    WorkflowTransition::query()->create([
        'workflow_id' => $workflow->id,
        'from_step_id' => $start->id,
        'to_step_id' => $end->id,
        'type' => 'forward',
    ]);
    WorkflowStepAction::query()->create([
        'workflow_id' => $workflow->id,
        'step_id' => $end->id,
        'code' => 'finish',
        'name' => 'Finish',
        'type' => 'approve',
    ]);

    $exit = Artisan::call('workflow:diagnose', ['workflow' => 'no-handler']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('no handler');
    expect($output)->toContain('finish');
});
