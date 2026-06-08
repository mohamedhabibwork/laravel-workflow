<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Attributes\Compilation\CompiledAction;
use HFlow\LaravelWorkflow\Attributes\Compilation\CompiledStep;
use HFlow\LaravelWorkflow\Attributes\Compilation\CompiledWorkflow;

it('serializes compiled workflow DTOs and fingerprints independently of version', function (): void {
    $step = new CompiledStep(
        code: 'start',
        name: 'Start',
        type: 'start',
        actions: [
            new CompiledAction(code: 'submit', name: 'Submit', type: 'submit'),
        ],
    );

    $v1 = new CompiledWorkflow(
        code: 'dto_test',
        name: 'DTO Test',
        subject: null,
        type: 'generic',
        version: 1,
        tenantId: null,
        steps: [$step],
    );

    $v2 = new CompiledWorkflow(
        code: 'dto_test',
        name: 'DTO Test',
        subject: null,
        type: 'generic',
        version: 2,
        tenantId: null,
        steps: [$step],
    );

    $decoded = json_decode(json_encode($v1, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['code'])->toBe('dto_test')
        ->and($decoded['steps'][0]['actions'][0]['code'])->toBe('submit')
        ->and($v1->fingerprint())->toBe($v2->fingerprint());
});
