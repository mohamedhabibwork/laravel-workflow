<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Attributes\Compilation\CompiledAction;
use HFlow\LaravelWorkflow\Attributes\Compilation\CompiledStep;
use HFlow\LaravelWorkflow\Attributes\Compilation\CompiledWorkflow;
use HFlow\LaravelWorkflow\Attributes\Compilation\InvariantChecker;

function attrBaseCompiledWorkflow(): CompiledWorkflow
{
    return new CompiledWorkflow(
        code: 'invariant',
        name: 'Invariant',
        subject: null,
        type: 'approval',
        version: 1,
        tenantId: null,
        steps: [
            new CompiledStep(
                code: 'start',
                name: 'Start',
                type: 'start',
                actions: [new CompiledAction(code: 'submit', name: 'Submit', type: 'submit')],
            ),
            new CompiledStep(
                code: 'review',
                name: 'Review',
                type: 'approval',
                actions: [
                    new CompiledAction(code: 'approve', name: 'Approve', type: 'approve'),
                    new CompiledAction(code: 'reject', name: 'Reject', type: 'reject', requiresComment: true),
                ],
            ),
            new CompiledStep(code: 'end', name: 'End', type: 'end'),
        ],
        transitions: [
            ['from' => 'start', 'to' => 'review', 'on' => 'submit', 'when' => null, 'priority' => 0, 'type' => 'forward'],
            ['from' => 'review', 'to' => 'end', 'on' => 'approve', 'when' => null, 'priority' => 0, 'type' => 'forward'],
        ],
    );
}

it('reports compile-time invariant violations', function (string $rule, Closure $mutate): void {
    config()->set('workflow.tenancy.enabled', false);

    $workflow = $mutate(attrBaseCompiledWorkflow());
    $rules = collect((new InvariantChecker)->check($workflow))->pluck('rule')->all();

    expect($rules)->toContain($rule);
})->with([
    'V-1 no start' => ['V-1', fn (CompiledWorkflow $w) => new CompiledWorkflow($w->code, $w->name, $w->subject, $w->type, $w->version, $w->tenantId, steps: array_slice($w->steps, 1), transitions: $w->transitions)],
    'V-2 no end' => ['V-2', fn (CompiledWorkflow $w) => new CompiledWorkflow($w->code, $w->name, $w->subject, $w->type, $w->version, $w->tenantId, steps: array_slice($w->steps, 0, 2), transitions: $w->transitions)],
    'V-3 missing from' => ['V-3', fn (CompiledWorkflow $w) => new CompiledWorkflow($w->code, $w->name, $w->subject, $w->type, $w->version, $w->tenantId, steps: $w->steps, transitions: [['from' => 'missing', 'to' => 'end', 'on' => 'submit', 'when' => null, 'priority' => 0, 'type' => 'forward']])],
    'V-4 missing to' => ['V-4', fn (CompiledWorkflow $w) => new CompiledWorkflow($w->code, $w->name, $w->subject, $w->type, $w->version, $w->tenantId, steps: $w->steps, transitions: [['from' => 'start', 'to' => 'missing', 'on' => 'submit', 'when' => null, 'priority' => 0, 'type' => 'forward']])],
    'V-5 duplicate step code' => ['V-5', fn (CompiledWorkflow $w) => new CompiledWorkflow($w->code, $w->name, $w->subject, $w->type, $w->version, $w->tenantId, steps: [...$w->steps, $w->steps[0]], transitions: $w->transitions)],
    'V-6 missing action' => ['V-6', fn (CompiledWorkflow $w) => new CompiledWorkflow($w->code, $w->name, $w->subject, $w->type, $w->version, $w->tenantId, steps: $w->steps, transitions: [['from' => 'start', 'to' => 'review', 'on' => 'missing', 'when' => null, 'priority' => 0, 'type' => 'forward']])],
    'V-7 reject requires comment' => ['V-7', fn (CompiledWorkflow $w) => new CompiledWorkflow($w->code, $w->name, $w->subject, $w->type, $w->version, $w->tenantId, steps: [$w->steps[0], new CompiledStep(code: 'review', name: 'Review', type: 'approval', actions: [new CompiledAction(code: 'reject', name: 'Reject', type: 'reject')]), $w->steps[2]], transitions: $w->transitions)],
    'V-8 invalid match mode' => ['V-8', fn (CompiledWorkflow $w) => new CompiledWorkflow($w->code, $w->name, $w->subject, $w->type, $w->version, $w->tenantId, steps: [$w->steps[0], new CompiledStep(code: 'review', name: 'Review', type: 'approval', matchMode: 'majority'), $w->steps[2]], transitions: $w->transitions)],
    'V-10 missing class' => ['V-10', fn (CompiledWorkflow $w) => new CompiledWorkflow($w->code, $w->name, 'Missing\\Order', $w->type, $w->version, $w->tenantId, steps: $w->steps, transitions: $w->transitions)],
]);

it('reports V-11 when tenancy is enabled and no tenant id is present', function (): void {
    config()->set('workflow.tenancy.enabled', true);

    $rules = collect((new InvariantChecker)->check(attrBaseCompiledWorkflow()))->pluck('rule')->all();

    expect($rules)->toContain('V-11');

    config()->set('workflow.tenancy.enabled', false);
});
