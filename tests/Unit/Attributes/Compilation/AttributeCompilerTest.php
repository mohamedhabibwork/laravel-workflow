<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Attributes\Compilation\AttributeCompiler;
use HFlow\LaravelWorkflow\Attributes\Compilation\CompileContext;
use HFlow\LaravelWorkflow\Exceptions\InvalidExpressionException;
use HFlow\LaravelWorkflow\Tests\Fixtures\AttributeCompilerWorkflow;
use HFlow\LaravelWorkflow\Tests\Fixtures\InvalidAttributeExpressionWorkflow;

it('compiles an attributed workflow class into the expected DTO tree', function (): void {
    $compiler = new AttributeCompiler;

    $compiled = $compiler->compile(AttributeCompilerWorkflow::class, new CompileContext);

    expect($compiled->code)->toBe('attribute_fixture')
        ->and($compiled->type)->toBe('approval')
        ->and($compiled->steps)->toHaveCount(4)
        ->and($compiled->transitions)->toHaveCount(3)
        ->and($compiled->assignees)->toHaveCount(1);

    $review = collect($compiled->steps)->first(fn ($step) => $step->code === 'review');

    expect($review->actions)->toHaveCount(2)
        ->and($review->actions[0]->guardCondition['clauses'][0]['operator'])->toBe('gt')
        ->and($compiled->transitions[1]['when']['clauses'][0]['operator'])->toBe('gte');
});

it('throws an InvalidExpressionException for malformed inline expressions', function (): void {
    $compiler = new AttributeCompiler;

    expect(fn () => $compiler->compile(InvalidAttributeExpressionWorkflow::class, new CompileContext))
        ->toThrow(InvalidExpressionException::class);
});
