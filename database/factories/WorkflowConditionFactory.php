<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\ConditionKind;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowCondition>
 */
final class WorkflowConditionFactory extends Factory
{
    protected $model = WorkflowCondition::class;

    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'name' => $this->faker->words(2, true),
            'code' => $this->faker->unique()->slug(2),
            'kind' => ConditionKind::Expression->value,
            'expression' => ['op' => 'eq', 'field' => 'subject.amount', 'value' => 100],
            'evaluator' => null,
        ];
    }

    public function custom(): self
    {
        return $this->state(fn (): array => [
            'kind' => ConditionKind::Custom->value,
            'evaluator' => 'App\\Evaluators\\FakeConditionEvaluator',
        ]);
    }

    public function group(): self
    {
        return $this->state(fn (): array => [
            'kind' => ConditionKind::Composite->value,
            'expression' => ['op' => 'and', 'clauses' => []],
        ]);
    }
}
