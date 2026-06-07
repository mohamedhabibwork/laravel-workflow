<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Conditions;

use HFlow\LaravelWorkflow\Engines\Expressions\ClauseGroup;
use HFlow\LaravelWorkflow\Engines\Expressions\Expression;

/**
 * Routes a condition payload to the appropriate evaluator.
 *
 * The dispatcher is intentionally thin: each kind has its own concrete
 * evaluator. Composite groups are recursed into via
 * {@see ExpressionConditionEvaluator}.
 */
final class ConditionEvaluator
{
    public function __construct(
        private readonly ExpressionConditionEvaluator $expression,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function evaluate(array $payload, array $context): bool
    {
        // Composite groups (any condition that has a `clauses` or `groups`
        // array) are always evaluated by the structured evaluator.
        $isComposite = isset($payload['clauses']) || isset($payload['groups']) || isset($payload['op']);

        if ($isComposite) {
            return $this->expression->evaluate(Expression::fromArray($payload), $context);
        }

        // Single leaf: {field, operator, value}
        if (isset($payload['field'], $payload['operator'])) {
            $clause = [
                'clauses' => [['field' => $payload['field'], 'operator' => $payload['operator'], 'value' => $payload['value'] ?? null]],
                'op' => ClauseGroup::OP_AND,
            ];

            return $this->expression->evaluateArray($clause, $context);
        }

        return false;
    }
}
