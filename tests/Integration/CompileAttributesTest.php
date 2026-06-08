<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Order;

beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    Schema::create('host_orders_attributes', function ($table): void {
        $table->id('id');
        $table->string('reference')->nullable();
        $table->timestamps();
    });
});

it('compiles attributed workflow classes into rows the engine can activate and execute', function (): void {
    $exit = Artisan::call('workflow:compile-attributes', [
        '--path' => 'workbench/app/Workflows/OrderApprovalWorkflow.php',
    ]);

    expect($exit)->toBe(0);

    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = Workflow::query()
        ->where('code', 'attribute_order_approval')
        ->where('version', 1)
        ->firstOrFail();

    $engine->activate($workflow);

    $order = Order::query()->create(['reference' => 'ATTR-1']);
    $instance = $engine->start($workflow->refresh(), $order);
    $engine->perform($instance, 'submit');

    $actions = $engine->availableActions($instance->refresh());

    expect($actions->keys())->toBe(['approve', 'reject']);
});

it('is idempotent for unchanged compiles and bumps version when the attribute definition changes', function (): void {
    Artisan::call('workflow:compile-attributes', [
        '--path' => 'workbench/app/Workflows/OrderApprovalWorkflow.php',
    ]);

    $counts = [
        'workflows' => Workflow::query()->count(),
        'steps' => WorkflowStep::query()->count(),
        'actions' => WorkflowStepAction::query()->count(),
        'transitions' => WorkflowTransition::query()->count(),
    ];

    Artisan::call('workflow:compile-attributes', [
        '--path' => 'workbench/app/Workflows/OrderApprovalWorkflow.php',
    ]);

    expect(Workflow::query()->count())->toBe($counts['workflows'])
        ->and(WorkflowStep::query()->count())->toBe($counts['steps'])
        ->and(WorkflowStepAction::query()->count())->toBe($counts['actions'])
        ->and(WorkflowTransition::query()->count())->toBe($counts['transitions']);

    Artisan::call('workflow:compile-attributes', [
        '--path' => 'workbench/app/Workflows/OrderApprovalWorkflowV2.php',
    ]);

    $versions = Workflow::query()
        ->where('code', 'attribute_order_approval')
        ->orderByDesc('version')
        ->pluck('version')
        ->all();

    expect($versions)->toBe([2, 1]);
});
