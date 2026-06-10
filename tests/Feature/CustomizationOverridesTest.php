<?php

use HFlow\LaravelWorkflow\Builders\WorkflowBuilder;
use HFlow\LaravelWorkflow\LaravelWorkflow;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Tests\Models\TestSubject;
use HFlow\LaravelWorkflow\Tests\Support\CustomLaravelWorkflow;
use HFlow\LaravelWorkflow\Tests\Support\CustomWorkflowBuilder;
use HFlow\LaravelWorkflow\Tests\Support\CustomWorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('clients can override package classes through configuration', function () {
    config([
        'workflow.classes.api' => CustomLaravelWorkflow::class,
        'workflow.classes.workflow_builder' => CustomWorkflowBuilder::class,
        'workflow.classes.workflow_engine' => CustomWorkflowEngine::class,
    ]);

    app()->forgetInstance(LaravelWorkflow::class);
    app()->forgetInstance(WorkflowEngine::class);

    $workflow = app(LaravelWorkflow::class);
    $engine = app(WorkflowEngine::class);
    $subject = TestSubject::create(['name' => 'Customizable Subject']);
    $builder = $subject->workflow();

    expect($workflow)->toBeInstanceOf(CustomLaravelWorkflow::class);
    expect($workflow->customized())->toBeTrue();
    expect($engine)->toBeInstanceOf(CustomWorkflowEngine::class);
    expect($engine->customized())->toBeTrue();
    expect($builder)->toBeInstanceOf(CustomWorkflowBuilder::class);
    expect($builder)->toBeInstanceOf(WorkflowBuilder::class);
    expect($builder->customized())->toBeTrue();
});

test('invalid package class overrides fall back to defaults', function () {
    config([
        'workflow.classes.api' => stdClass::class,
        'workflow.classes.workflow_builder' => stdClass::class,
        'workflow.classes.workflow_engine' => stdClass::class,
    ]);

    app()->forgetInstance(LaravelWorkflow::class);
    app()->forgetInstance(WorkflowEngine::class);

    $workflow = app(LaravelWorkflow::class);
    $engine = app(WorkflowEngine::class);
    $subject = TestSubject::create(['name' => 'Fallback Subject']);

    expect($workflow)->toBeInstanceOf(LaravelWorkflow::class);
    expect($workflow)->not->toBeInstanceOf(CustomLaravelWorkflow::class);
    expect($engine)->toBeInstanceOf(WorkflowEngine::class);
    expect($engine)->not->toBeInstanceOf(CustomWorkflowEngine::class);
    expect($subject->workflow())->toBeInstanceOf(WorkflowBuilder::class);
});
