<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Engines\WorkflowEngine as WorkflowEngineImpl;

/**
 * T020 — Contract test for the {@see WorkflowEngine} interface.
 *
 * Verifies that the default implementation:
 *   - Is bound in the service container as a singleton
 *   - Implements every method declared in the contract
 *   - Exposes the exact parameter and return types from
 *     `specs/.../contracts/workflow-engine.md` §2.12-2.14 (US1 surface)
 */
it('binds the default WorkflowEngine implementation as a singleton', function (): void {
    $a = app(WorkflowEngine::class);
    $b = app(WorkflowEngine::class);

    expect($a)->toBeInstanceOf(WorkflowEngineImpl::class)
        ->and($a)->toBe($b);
});

it('implements every method declared in the WorkflowEngine contract', function (string $method): void {
    expect(method_exists(WorkflowEngineImpl::class, $method))->toBeTrue(
        "WorkflowEngine::{$method}() is required by the contract",
    );
})->with([
    'define', 'activate', 'versions', 'createNewVersion',
    'start', 'currentStep', 'availableActions', 'perform',
    'skip', 'return',
    'hold', 'resume', 'cancel',
    'history',
]);

it('exposes the exact US2 signatures required by the contract', function (): void {
    $ref = new ReflectionClass(WorkflowEngineImpl::class);

    // start(Workflow|string, Model, array=[], mixed|null): WorkflowInstance
    $m = $ref->getMethod('start');
    $params = $m->getParameters();
    expect($params)->toHaveCount(4)
        ->and($params[0]->getName())->toBe('workflow')
        ->and((string) $params[0]->getType())->toBe('HFlow\\LaravelWorkflow\\Models\\Workflow|string')
        ->and($params[1]->getName())->toBe('subject')
        ->and((string) $params[1]->getType())->toBe('Illuminate\\Database\\Eloquent\\Model')
        ->and($params[2]->getName())->toBe('context')
        ->and($params[2]->isDefaultValueAvailable())->toBeTrue()
        ->and($params[3]->getName())->toBe('initiator')
        ->and((string) $m->getReturnType())->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowInstance');

    // currentStep(WorkflowInstance): WorkflowStepInstance|Collection
    $m = $ref->getMethod('currentStep');
    $params = $m->getParameters();
    expect($params)->toHaveCount(1)
        ->and($params[0]->getName())->toBe('instance')
        ->and((string) $params[0]->getType())->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowInstance')
        ->and((string) $m->getReturnType())
        ->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowStepInstance|Illuminate\\Support\\Collection');
});

it('exposes the exact US3 signatures required by the contract', function (): void {
    $ref = new ReflectionClass(WorkflowEngineImpl::class);

    // availableActions(WorkflowInstance, mixed = null): ActionSet
    $m = $ref->getMethod('availableActions');
    $params = $m->getParameters();
    expect($params)->toHaveCount(2)
        ->and($params[0]->getName())->toBe('instance')
        ->and((string) $params[0]->getType())->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowInstance')
        ->and($params[1]->getName())->toBe('user')
        ->and($params[1]->isDefaultValueAvailable())->toBeTrue()
        ->and((string) $m->getReturnType())->toBe('HFlow\\LaravelWorkflow\\Actions\\ActionSet');

    // perform(WorkflowInstance, string, mixed = null, ?array = null): WorkflowInstance
    $m = $ref->getMethod('perform');
    $params = $m->getParameters();
    expect($params)->toHaveCount(4)
        ->and($params[0]->getName())->toBe('instance')
        ->and((string) $params[0]->getType())->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowInstance')
        ->and($params[1]->getName())->toBe('actionCode')
        ->and((string) $params[1]->getType())->toBe('string')
        ->and($params[2]->getName())->toBe('user')
        ->and($params[2]->isDefaultValueAvailable())->toBeTrue()
        ->and($params[3]->getName())->toBe('payload')
        ->and($params[3]->isDefaultValueAvailable())->toBeTrue()
        ->and((string) $m->getReturnType())->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowInstance');
});

it('exposes the exact US1 signatures required by the contract', function (): void {
    $ref = new ReflectionClass(WorkflowEngineImpl::class);

    // versions(Workflow|string): Collection
    $m = $ref->getMethod('versions');
    $params = $m->getParameters();
    expect($params)->toHaveCount(1)
        ->and($params[0]->getName())->toBe('workflow')
        ->and((string) $params[0]->getType())->toBe('HFlow\\LaravelWorkflow\\Models\\Workflow|string')
        ->and((string) $m->getReturnType())->toBe('Illuminate\\Support\\Collection');

    // createNewVersion(Workflow, array=[]): Workflow
    $m = $ref->getMethod('createNewVersion');
    $params = $m->getParameters();
    expect($params)->toHaveCount(2)
        ->and($params[0]->getName())->toBe('workflow')
        ->and((string) $params[0]->getType())->toBe('HFlow\\LaravelWorkflow\\Models\\Workflow')
        ->and($params[1]->getName())->toBe('overrides')
        ->and($params[1]->isDefaultValueAvailable())->toBeTrue()
        ->and((string) $m->getReturnType())->toBe('HFlow\\LaravelWorkflow\\Models\\Workflow');

    // activate(Workflow|string): Workflow
    $m = $ref->getMethod('activate');
    $params = $m->getParameters();
    expect($params)->toHaveCount(1)
        ->and($params[0]->getName())->toBe('workflow')
        ->and((string) $params[0]->getType())->toBe('HFlow\\LaravelWorkflow\\Models\\Workflow|string')
        ->and((string) $m->getReturnType())->toBe('HFlow\\LaravelWorkflow\\Models\\Workflow');

    // define(string, array): Workflow
    $m = $ref->getMethod('define');
    $params = $m->getParameters();
    expect($params)->toHaveCount(2)
        ->and($params[0]->getName())->toBe('key')
        ->and((string) $params[0]->getType())->toBe('string')
        ->and((string) $m->getReturnType())->toBe('HFlow\\LaravelWorkflow\\Models\\Workflow');
});

it('exposes the exact US4 signatures required by the contract', function (): void {
    $ref = new ReflectionClass(WorkflowEngineImpl::class);

    // skip(WorkflowInstance, mixed=null, ?string=null): WorkflowInstance
    $m = $ref->getMethod('skip');
    $params = $m->getParameters();
    expect($params)->toHaveCount(3)
        ->and($params[0]->getName())->toBe('instance')
        ->and((string) $params[0]->getType())->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowInstance')
        ->and($params[1]->getName())->toBe('user')
        ->and($params[1]->isDefaultValueAvailable())->toBeTrue()
        ->and($params[2]->getName())->toBe('comment')
        ->and($params[2]->isDefaultValueAvailable())->toBeTrue()
        ->and((string) $m->getReturnType())->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowInstance');

    // return(WorkflowInstance, WorkflowStep|string|null=null, mixed=null, ?string=null): WorkflowInstance
    $m = $ref->getMethod('return');
    $params = $m->getParameters();
    expect($params)->toHaveCount(4)
        ->and($params[0]->getName())->toBe('instance')
        ->and((string) $params[0]->getType())->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowInstance')
        ->and($params[1]->getName())->toBe('targetStep')
        ->and((string) $params[1]->getType())->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowStep|string|null')
        ->and($params[1]->isDefaultValueAvailable())->toBeTrue()
        ->and($params[2]->getName())->toBe('user')
        ->and($params[2]->isDefaultValueAvailable())->toBeTrue()
        ->and($params[3]->getName())->toBe('comment')
        ->and($params[3]->isDefaultValueAvailable())->toBeTrue()
        ->and((string) $m->getReturnType())->toBe('HFlow\\LaravelWorkflow\\Models\\WorkflowInstance');
});
