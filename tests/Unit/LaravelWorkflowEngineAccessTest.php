<?php

use HFlow\LaravelWorkflow\LaravelWorkflow;
use HFlow\LaravelWorkflow\Services\ActionResolver;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Services\WorkflowService;

test('the workflow engine can be read from the public package api', function () {
    $engine = new WorkflowEngine;
    $workflow = new LaravelWorkflow($engine, new WorkflowService, new ActionResolver);

    expect($workflow->getEngine())->toBe($engine);
    expect($workflow->engine())->toBe($engine);
});

test('the workflow engine can be replaced on the public package api', function () {
    $workflow = new LaravelWorkflow(new WorkflowEngine, new WorkflowService, new ActionResolver);
    $replacement = new WorkflowEngine;

    expect($workflow->setEngine($replacement))->toBe($workflow);
    expect($workflow->getEngine())->toBe($replacement);

    $anotherReplacement = new WorkflowEngine;

    expect($workflow->useEngine($anotherReplacement))->toBe($workflow);
    expect($workflow->engine())->toBe($anotherReplacement);
});
