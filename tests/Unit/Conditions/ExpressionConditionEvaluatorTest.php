<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Engines\Conditions\ExpressionConditionEvaluator;
use HFlow\LaravelWorkflow\Enums\Operator;
use HFlow\LaravelWorkflow\Exceptions\InvalidExpressionException;

/**
 * T048 — Unit tests for the 14 operators + recursion/clause caps.
 */

it('evaluates eq against a subject path', function (): void {
    $ev = new ExpressionConditionEvaluator;
    $result = $ev->evaluateArray([
        'op' => 'and',
        'clauses' => [['field' => 'subject.amount', 'operator' => 'eq', 'value' => 100]],
    ], ['subject' => ['amount' => 100]]);

    expect($result)->toBeTrue();
});

it('evaluates neq, gt, gte, lt, lte', function (): void {
    $ev = new ExpressionConditionEvaluator;
    $context = ['subject' => ['amount' => 10]];

    $cases = [
        [['field' => 'subject.amount', 'operator' => 'neq', 'value' => 5], true],
        [['field' => 'subject.amount', 'operator' => 'gt',  'value' => 5], true],
        [['field' => 'subject.amount', 'operator' => 'gte', 'value' => 10], true],
        [['field' => 'subject.amount', 'operator' => 'lt',  'value' => 20], true],
        [['field' => 'subject.amount', 'operator' => 'lte', 'value' => 10], true],
        [['field' => 'subject.amount', 'operator' => 'gt',  'value' => 10], false],
    ];

    foreach ($cases as [$clause, $expected]) {
        $result = $ev->evaluateArray([
            'op' => 'and',
            'clauses' => [$clause],
        ], $context);
        expect($result)->toBe($expected, "Failed for operator={$clause['operator']}, value={$clause['value']}");
    }
});

it('evaluates in and not_in against array values', function (): void {
    $ev = new ExpressionConditionEvaluator;

    expect($ev->evaluateArray([
        'op' => 'and', 'clauses' => [['field' => 'subject.role', 'operator' => 'in', 'value' => ['admin', 'manager']]],
    ], ['subject' => ['role' => 'manager']]))->toBeTrue();

    expect($ev->evaluateArray([
        'op' => 'and', 'clauses' => [['field' => 'subject.role', 'operator' => 'in', 'value' => ['admin', 'manager']]],
    ], ['subject' => ['role' => 'guest']]))->toBeFalse();

    expect($ev->evaluateArray([
        'op' => 'and', 'clauses' => [['field' => 'subject.role', 'operator' => 'not_in', 'value' => ['admin', 'manager']]],
    ], ['subject' => ['role' => 'guest']]))->toBeTrue();
});

it('evaluates contains, starts_with, ends_with', function (): void {
    $ev = new ExpressionConditionEvaluator;
    $context = ['subject' => ['title' => 'Hello world']];

    expect($ev->evaluateArray(['op' => 'and', 'clauses' => [['field' => 'subject.title', 'operator' => 'contains', 'value' => 'world']]], $context))->toBeTrue();
    expect($ev->evaluateArray(['op' => 'and', 'clauses' => [['field' => 'subject.title', 'operator' => 'starts_with', 'value' => 'Hello']]], $context))->toBeTrue();
    expect($ev->evaluateArray(['op' => 'and', 'clauses' => [['field' => 'subject.title', 'operator' => 'ends_with', 'value' => 'world']]], $context))->toBeTrue();
    expect($ev->evaluateArray(['op' => 'and', 'clauses' => [['field' => 'subject.title', 'operator' => 'starts_with', 'value' => 'Bye']]], $context))->toBeFalse();
});

it('evaluates is_null, is_not_null, is_true', function (): void {
    $ev = new ExpressionConditionEvaluator;
    $context = ['subject' => ['deleted' => null, 'active' => true, 'inactive' => false]];

    expect($ev->evaluateArray(['op' => 'and', 'clauses' => [['field' => 'subject.deleted', 'operator' => 'is_null']]], $context))->toBeTrue();
    expect($ev->evaluateArray(['op' => 'and', 'clauses' => [['field' => 'subject.active', 'operator' => 'is_not_null']]], $context))->toBeTrue();
    expect($ev->evaluateArray(['op' => 'and', 'clauses' => [['field' => 'subject.active', 'operator' => 'is_true']]], $context))->toBeTrue();
    expect($ev->evaluateArray(['op' => 'and', 'clauses' => [['field' => 'subject.inactive', 'operator' => 'is_true']]], $context))->toBeFalse();
});

it('returns false for missing fields (treated as null)', function (): void {
    $ev = new ExpressionConditionEvaluator;
    expect($ev->evaluateArray([
        'op' => 'and', 'clauses' => [['field' => 'subject.missing', 'operator' => 'eq', 'value' => null]],
    ], ['subject' => []]))->toBeTrue();

    expect($ev->evaluateArray([
        'op' => 'and', 'clauses' => [['field' => 'subject.missing', 'operator' => 'is_null']],
    ], []))->toBeTrue();
});

it('evaluates AND groups (all clauses must pass)', function (): void {
    $ev = new ExpressionConditionEvaluator;
    $context = ['subject' => ['amount' => 1500, 'currency' => 'USD']];

    expect($ev->evaluateArray([
        'op' => 'and',
        'clauses' => [
            ['field' => 'subject.amount', 'operator' => 'gt', 'value' => 1000],
            ['field' => 'subject.currency', 'operator' => 'eq', 'value' => 'USD'],
        ],
    ], $context))->toBeTrue();

    expect($ev->evaluateArray([
        'op' => 'and',
        'clauses' => [
            ['field' => 'subject.amount', 'operator' => 'gt', 'value' => 1000],
            ['field' => 'subject.currency', 'operator' => 'eq', 'value' => 'EUR'],
        ],
    ], $context))->toBeFalse();
});

it('evaluates OR groups (any clause passing)', function (): void {
    $ev = new ExpressionConditionEvaluator;
    $context = ['subject' => ['amount' => 500]];

    expect($ev->evaluateArray([
        'op' => 'or',
        'clauses' => [
            ['field' => 'subject.amount', 'operator' => 'gt', 'value' => 1000],
            ['field' => 'subject.amount', 'operator' => 'lt', 'value' => 100],
        ],
    ], $context))->toBeFalse();

    expect($ev->evaluateArray([
        'op' => 'or',
        'clauses' => [
            ['field' => 'subject.amount', 'operator' => 'gt', 'value' => 100],
            ['field' => 'subject.amount', 'operator' => 'lt', 'value' => 1000],
        ],
    ], $context))->toBeTrue();
});

it('evaluates nested AND/OR groups', function (): void {
    $ev = new ExpressionConditionEvaluator;
    $context = ['subject' => ['amount' => 1500, 'role' => 'admin']];

    // (amount > 1000) AND (role = admin OR role = super)
    $result = $ev->evaluateArray([
        'op' => 'and',
        'clauses' => [
            ['field' => 'subject.amount', 'operator' => 'gt', 'value' => 1000],
        ],
        'groups' => [
            [
                'op' => 'or',
                'clauses' => [
                    ['field' => 'subject.role', 'operator' => 'eq', 'value' => 'admin'],
                    ['field' => 'subject.role', 'operator' => 'eq', 'value' => 'super'],
                ],
            ],
        ],
    ], $context);

    expect($result)->toBeTrue();
});

it('returns true for empty groups (vacuous truth)', function (): void {
    $ev = new ExpressionConditionEvaluator;
    expect($ev->evaluateArray(['op' => 'and', 'clauses' => []], []))->toBeTrue();
    expect($ev->evaluateArray(['op' => 'or', 'clauses' => []], []))->toBeTrue();
});

it('throws InvalidExpressionException when recursion depth exceeds the cap', function (): void {
    $ev = new ExpressionConditionEvaluator;

    // Build a deeply nested structure
    $group = ['op' => 'and', 'clauses' => []];
    $cursor = &$group;
    for ($i = 0; $i < ExpressionConditionEvaluator::MAX_RECURSION_DEPTH + 2; $i++) {
        $next = ['op' => 'and', 'clauses' => []];
        $cursor['groups'][] = $next;
        $cursor = &$cursor['groups'][0];
    }
    unset($cursor);

    expect(fn () => $ev->evaluateArray($group, []))->toThrow(InvalidExpressionException::class);
});

it('throws InvalidExpressionException when clause count exceeds the cap', function (): void {
    $ev = new ExpressionConditionEvaluator;

    $clauses = [];
    for ($i = 0; $i < ExpressionConditionEvaluator::MAX_CLAUSE_COUNT + 1; $i++) {
        $clauses[] = ['field' => 'subject.x', 'operator' => 'eq', 'value' => $i];
    }

    expect(fn () => $ev->evaluateArray(['op' => 'and', 'clauses' => $clauses], ['subject' => ['x' => 0]]))
        ->toThrow(InvalidExpressionException::class);
});

it('Operator enum exposes the 14 expected values', function (): void {
    expect(Operator::values())->toHaveCount(14)
        ->and(Operator::Eq->value)->toBe('eq')
        ->and(Operator::NotEq->value)->toBe('neq')
        ->and(Operator::IsTrue->value)->toBe('is_true');
});

it('Field::resolve walks dotted paths across subject/context/user/instance', function (): void {
    $context = [
        'subject' => ['order' => ['total' => 250]],
        'context' => ['feature' => 'v2'],
        'user' => ['id' => 7],
        'instance' => ['uuid' => 'inst-123'],
    ];

    expect(\HFlow\LaravelWorkflow\Engines\Expressions\Field::resolve('subject.order.total', $context))->toBe(250);
    expect(\HFlow\LaravelWorkflow\Engines\Expressions\Field::resolve('context.feature', $context))->toBe('v2');
    expect(\HFlow\LaravelWorkflow\Engines\Expressions\Field::resolve('user.id', $context))->toBe(7);
    expect(\HFlow\LaravelWorkflow\Engines\Expressions\Field::resolve('instance.uuid', $context))->toBe('inst-123');
    expect(\HFlow\LaravelWorkflow\Engines\Expressions\Field::resolve('missing', $context))->toBeNull();
    expect(\HFlow\LaravelWorkflow\Engines\Expressions\Field::resolve('subject.missing.deep', $context))->toBeNull();
});
