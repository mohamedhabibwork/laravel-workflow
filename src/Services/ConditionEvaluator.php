<?php

namespace HFlow\LaravelWorkflow\Services;

use HFlow\LaravelWorkflow\Contracts\CustomConditionEvaluator;
use HFlow\LaravelWorkflow\Enums\ConditionKind;
use HFlow\LaravelWorkflow\Models\WorkflowCondition;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Contracts\Container\Container;

class ConditionEvaluator
{
    /**
     * Create the evaluator with the container used for custom evaluator classes.
     */
    public function __construct(
        protected ?Container $container = null,
    ) {
        $this->container ??= \Illuminate\Container\Container::getInstance();
    }

    /**
     * Evaluate a workflow condition against the instance, subject, context, and optional user.
     *
     * @param  array<string, mixed>  $context
     */
    public function evaluate(?WorkflowCondition $condition, WorkflowInstance $instance, mixed $subject, array $context, ?User $user = null): bool
    {
        if ($condition === null) {
            return true;
        }

        return match ($condition->kind) {
            ConditionKind::Expression => $this->evaluateExpression($condition->expression ?? [], $instance, $subject, $context),
            ConditionKind::Custom => $this->evaluateCustom($condition->evaluator ?? '', $instance, $subject, $context, $user),
            ConditionKind::Composite => $this->evaluateComposite($condition->expression ?? [], $instance, $subject, $context, $user),
        };
    }

    /**
     * Evaluate a structured expression.
     *
     * Expression: {logic:"and|or", clauses:[{field,operator,value}], groups:[…]}
     *
     * @param  array{
     *     logic?: string,
     *     clauses?: array<int, array<string, mixed>>,
     *     groups?: array<int, array<string, mixed>>
     * }  $expression
     * @param  array<string, mixed>  $context
     */
    protected function evaluateExpression(array $expression, WorkflowInstance $instance, mixed $subject, array $context): bool
    {
        $logic = strtolower($expression['logic'] ?? 'and');
        $clauses = $expression['clauses'] ?? [];

        $results = [];

        foreach ($clauses as $clause) {
            $results[] = $this->evaluateClause($clause, $instance, $subject, $context);
        }

        if ($logic === 'or') {
            return in_array(true, $results, true);
        }

        return ! in_array(false, $results, true);
    }

    /**
     * Evaluate a single field/operator/value expression clause.
     *
     * @param  array{field: string, operator: string, value: mixed}  $clause
     * @param  array<string, mixed>  $context
     */
    protected function evaluateClause(array $clause, WorkflowInstance $instance, mixed $subject, array $context): bool
    {
        $field = $clause['field'];
        $operator = $clause['operator'];
        $expectedValue = $clause['value'];

        $actualValue = $this->getValue($field, $instance, $subject, $context);

        return match ($operator) {
            'eq', '==' => $actualValue == $expectedValue,
            'neq', '!=' => $actualValue != $expectedValue,
            'gt', '>' => $actualValue > $expectedValue,
            'gte', '>=' => $actualValue >= $expectedValue,
            'lt', '<' => $actualValue < $expectedValue,
            'lte', '<=' => $actualValue <= $expectedValue,
            'in' => in_array($actualValue, (array) $expectedValue),
            'contains' => str_contains((string) $actualValue, (string) $expectedValue),
            default => false,
        };
    }

    /**
     * Resolve a field value from workflow context first, then from the subject.
     *
     * @param  array<string, mixed>  $context
     */
    protected function getValue(string $field, WorkflowInstance $instance, mixed $subject, array $context): mixed
    {
        // Check context first
        if (array_key_exists($field, $context)) {
            return $context[$field];
        }

        // Check subject attributes
        if (is_object($subject) && isset($subject->{$field})) {
            return $subject->{$field};
        }

        if (is_array($subject) && array_key_exists($field, $subject)) {
            return $subject[$field];
        }

        return null;
    }

    /**
     * Resolve and evaluate a custom condition evaluator class.
     *
     * @param  array<string, mixed>  $context
     */
    protected function evaluateCustom(string $className, WorkflowInstance $instance, mixed $subject, array $context, ?User $user = null): bool
    {
        if (empty($className) || ! class_exists($className)) {
            return false;
        }

        $evaluator = $this->container->make($className);

        if (! $evaluator instanceof CustomConditionEvaluator) {
            return false;
        }

        return $evaluator->evaluate($instance, $subject, $context, $user);
    }

    /**
     * Evaluate composite conditions with nested expression groups.
     *
     * @param  array{
     *     logic?: string,
     *     clauses?: array<int, array<string, mixed>>,
     *     groups?: array<int, array<string, mixed>>
     * }  $expression
     * @param  array<string, mixed>  $context
     */
    protected function evaluateComposite(array $expression, WorkflowInstance $instance, mixed $subject, array $context, ?User $user = null): bool
    {
        // Recursive implementation of groups
        $logic = strtolower($expression['logic'] ?? 'and');
        $groups = $expression['groups'] ?? [];

        $results = [];

        // Base clauses
        if (! empty($expression['clauses'])) {
            $results[] = $this->evaluateExpression($expression, $instance, $subject, $context);
        }

        // Nested groups
        foreach ($groups as $group) {
            $results[] = $this->evaluateComposite($group, $instance, $subject, $context, $user);
        }

        if ($logic === 'or') {
            return in_array(true, $results, true);
        }

        return ! in_array(false, $results, true);
    }
}
