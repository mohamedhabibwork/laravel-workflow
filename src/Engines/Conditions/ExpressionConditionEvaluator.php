<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Conditions;

use HFlow\LaravelWorkflow\Enums\ConditionKind;
use HFlow\LaravelWorkflow\Enums\Operator;
use HFlow\LaravelWorkflow\Exceptions\InvalidExpressionException;
use HFlow\LaravelWorkflow\Engines\Expressions\Clause;
use HFlow\LaravelWorkflow\Engines\Expressions\ClauseGroup;
use HFlow\LaravelWorkflow\Engines\Expressions\Expression;
use HFlow\LaravelWorkflow\Engines\Expressions\Field;

/**
 * Evaluates a structured `field/operator/value` condition JSON.
 *
 * Supports the `subject.*` / `context.*` / `user.*` / `instance.*` paths,
 * the 14 {@see Operator} values, and recursive AND/OR groups.
 *
 * Hard caps (raise {@see InvalidExpressionException} on violation):
 *   - Recursion depth: 10 levels
 *   - Clause count:    100 leaves
 */
final class ExpressionConditionEvaluator
{
    public const MAX_RECURSION_DEPTH = 10;
    public const MAX_CLAUSE_COUNT = 100;

    private int $clauseCount = 0;

    public function evaluate(Expression $expression, array $context): bool
    {
        $this->clauseCount = 0;

        return $this->evaluateGroup($expression->root, $context, depth: 0);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $context
     */
    public function evaluateArray(array $raw, array $context): bool
    {
        return $this->evaluate(Expression::fromArray($raw), $context);
    }

    private function evaluateGroup(ClauseGroup $group, array $context, int $depth): bool
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            throw new InvalidExpressionException(
                "Condition group exceeds max recursion depth of " . self::MAX_RECURSION_DEPTH,
            );
        }

        $results = [];

        foreach ($group->clauses as $clause) {
            $this->clauseCount++;
            if ($this->clauseCount > self::MAX_CLAUSE_COUNT) {
                throw new InvalidExpressionException(
                    "Condition exceeds max clause count of " . self::MAX_CLAUSE_COUNT,
                );
            }

            $results[] = $this->evaluateClause($clause, $context);
        }

        foreach ($group->groups as $subGroup) {
            $results[] = $this->evaluateGroup($subGroup, $context, $depth + 1);
        }

        if ($results === []) {
            return true;
        }

        return $group->op === ClauseGroup::OP_OR
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function evaluateClause(Clause $clause, array $context): bool
    {
        $left = Field::resolve($clause->field, $context);

        return match ($clause->operator) {
            Operator::Eq => $left == $clause->value,
            Operator::NotEq => $left != $clause->value,
            Operator::Gt => is_numeric($left) && is_numeric($clause->value) && $left > $clause->value,
            Operator::Gte => is_numeric($left) && is_numeric($clause->value) && $left >= $clause->value,
            Operator::Lt => is_numeric($left) && is_numeric($clause->value) && $left < $clause->value,
            Operator::Lte => is_numeric($left) && is_numeric($clause->value) && $left <= $clause->value,
            Operator::In => is_array($clause->value) && in_array($left, $clause->value, false),
            Operator::NotIn => is_array($clause->value) && ! in_array($left, $clause->value, false),
            Operator::Contains => is_string($left) && is_string($clause->value) && str_contains($left, $clause->value),
            Operator::StartsWith => is_string($left) && is_string($clause->value) && str_starts_with($left, $clause->value),
            Operator::EndsWith => is_string($left) && is_string($clause->value) && str_ends_with($left, $clause->value),
            Operator::IsNull => $left === null,
            Operator::IsNotNull => $left !== null,
            Operator::IsTrue => $left === true,
        };
    }
}
